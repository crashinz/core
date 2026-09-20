<?php
declare(strict_types=1);

// Consensual member-profile relationships. Independent of room Link/Lap groups.
function profile_relationship_member(PDO $pdo, int $userId): ?array
{
    if (account_deletion_is_deleted($pdo, $userId)) return null;
    $q = $pdo->prepare('SELECT id,username,display_name FROM users WHERE id=?');
    $q->execute([$userId]);
    $row = $q->fetch();
    return $row ? ['userId'=>(int)$row['id'], 'username'=>(string)$row['username'],
        'displayName'=>member_profiles_effective_display_name((string)$row['username'], (string)$row['display_name'])] : null;
}

function profile_relationship_blocked(PDO $pdo, int $a, int $b): bool
{
    $q=$pdo->prepare('SELECT 1 FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?)');
    $q->execute([$a,$b,$b,$a]);
    return (bool)$q->fetchColumn();
}

function profile_relationship_active(PDO $pdo, int $userId, bool $lock=false): ?array
{
    $q=$pdo->prepare("SELECT * FROM profile_relationship_requests WHERE status='accepted' AND (requester_user_id=? OR recipient_user_id=?) LIMIT 1" . ($lock && db_driver($pdo)==='mysql' ? ' FOR UPDATE' : ''));
    $q->execute([$userId,$userId]);
    return $q->fetch() ?: null;
}

function profile_relationship_public_name(PDO $pdo, int $userId): ?string
{
    $row=profile_relationship_active($pdo,$userId);
    if (!$row) return null;
    $other=(int)$row['requester_user_id']===$userId ? (int)$row['recipient_user_id'] : (int)$row['requester_user_id'];
    $member=profile_relationship_member($pdo,$other);
    return $member ? $member['displayName'].' (@'.$member['username'].')' : null;
}

function profile_relationship_state(PDO $pdo, int $userId): array
{
    $q=$pdo->prepare("SELECT * FROM profile_relationship_requests WHERE status IN ('pending','accepted') AND (requester_user_id=? OR recipient_user_id=?) ORDER BY created_at DESC");
    $q->execute([$userId,$userId]);
    $result=['partner'=>null,'incoming'=>[],'outgoing'=>[]];
    foreach($q->fetchAll() as $row) {
        $out=(int)$row['requester_user_id']===$userId;
        $other=$out ? (int)$row['recipient_user_id'] : (int)$row['requester_user_id'];
        $member=profile_relationship_member($pdo,$other);
        if (!$member || ($row['status']==='pending' && profile_relationship_blocked($pdo,$userId,$other))) continue;
        $item=['id'=>$row['public_id'],'member'=>$member];
        if ($row['status']==='accepted') $result['partner']=$item;
        else $result[$out?'outgoing':'incoming'][]=$item;
    }
    return $result;
}

function profile_relationship_search(PDO $pdo, int $userId, string $query): array
{
    $query=trim($query);
    if ($query==='' || mb_strlen($query)>32) return [];
    $like=str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($query)).'%';
    $q=$pdo->prepare("SELECT u.id,u.username,u.display_name FROM users u WHERE u.id<>? AND LOWER(u.username) LIKE ? ESCAPE '!' AND NOT EXISTS (SELECT 1 FROM account_deletions d WHERE d.user_id=u.id) AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=u.id) OR (b.blocked_user_id=? AND b.blocker_user_id=u.id)) ORDER BY u.username LIMIT 10");
    $q->execute([$userId,$like,$userId,$userId]);
    return array_map(static fn($r)=>['username'=>(string)$r['username'],'displayName'=>member_profiles_effective_display_name((string)$r['username'],(string)$r['display_name'])],$q->fetchAll());
}

function profile_relationship_action(PDO $pdo, int $actor, string $action, array $input): array
{
    if (!in_array($action,['request','accept','decline','cancel','remove'],true)) throw new MemberProfileException('Unknown relationship action.','RELATIONSHIP_ACTION_INVALID');
    $id=trim((string)($input['id']??''));
    if ($action==='request') {
        $username=trim((string)($input['username']??''));
        if ($username==='' || mb_strlen($username)>32) throw new MemberProfileException('Choose an existing username.','RELATIONSHIP_MEMBER_INVALID');
        $q=$pdo->prepare('SELECT id FROM users WHERE LOWER(username)=LOWER(?) LIMIT 1');$q->execute([$username]);$other=(int)$q->fetchColumn();
    } else {
        $q=$pdo->prepare('SELECT * FROM profile_relationship_requests WHERE public_id=?');$q->execute([$id]);$row=$q->fetch();
        if (!$row || !in_array($actor,[(int)$row['requester_user_id'],(int)$row['recipient_user_id']],true)) throw new MemberProfileException('Request unavailable.','RELATIONSHIP_UNAVAILABLE',404);
        $other=(int)$row['requester_user_id']===$actor ? (int)$row['recipient_user_id'] : (int)$row['requester_user_id'];
    }
    if ($other<1 || $other===$actor) throw new MemberProfileException('Choose another existing username.','RELATIONSHIP_MEMBER_INVALID');
    $tx=database_transaction_begin($pdo,db_driver($pdo)==='sqlite');
    try {
        // Both people are locked in the same order, including competing accept/end operations.
        $ids=[$actor,$other];sort($ids,SORT_NUMERIC);
        foreach($ids as $uid) member_profiles_lock_account_for_update($pdo,$uid);
        if ($action==='request' || $action==='accept') {
            if (!profile_relationship_member($pdo,$actor) || !profile_relationship_member($pdo,$other) || profile_relationship_blocked($pdo,$actor,$other)) throw new MemberProfileException('This relationship request is unavailable.','RELATIONSHIP_UNAVAILABLE',409);
        }
        if ($action==='request') {
            if (profile_relationship_active($pdo,$actor,true) || profile_relationship_active($pdo,$other,true)) throw new MemberProfileException('One of you is already in a relationship. Remove it before sending a new request.','RELATIONSHIP_ALREADY_ACTIVE',409);
            $q=$pdo->prepare("SELECT * FROM profile_relationship_requests WHERE status='pending' AND ((requester_user_id=? AND recipient_user_id=?) OR (requester_user_id=? AND recipient_user_id=?))");$q->execute([$actor,$other,$other,$actor]);
            if (!$q->fetch()) {
                $q=$pdo->prepare("SELECT COUNT(*) FROM profile_relationship_requests WHERE requester_user_id=? AND status='pending'");$q->execute([$actor]);
                if ((int)$q->fetchColumn()>0) throw new MemberProfileException('Cancel your current request before sending another.','RELATIONSHIP_PENDING',409);
                $pdo->prepare("INSERT INTO profile_relationship_requests(public_id,requester_user_id,recipient_user_id,status) VALUES(?,?,?,'pending')")->execute([uuid_v4(),$actor,$other]);
            }
        } else {
            $q=$pdo->prepare('SELECT * FROM profile_relationship_requests WHERE public_id=?'.(db_driver($pdo)==='mysql'?' FOR UPDATE':''));$q->execute([$id]);$row=$q->fetch();
            $requester=(int)$row['requester_user_id'];$recipient=(int)$row['recipient_user_id'];
            if ((in_array($action,['accept','decline'],true) && $actor!==$recipient) || ($action==='cancel' && $actor!==$requester)) throw new MemberProfileException('You cannot perform that action.','RELATIONSHIP_FORBIDDEN',403);
            $wanted=['accept'=>'accepted','decline'=>'declined','cancel'=>'cancelled','remove'=>'ended'][$action];
            $expected=$action==='remove'?'accepted':'pending';
            if ($row['status']!==$wanted) {
                if ($row['status']!==$expected) throw new MemberProfileException('This request changed. Review the current relationship status.','RELATIONSHIP_CHANGED',409);
                if ($action==='accept' && (profile_relationship_active($pdo,$actor,true) || profile_relationship_active($pdo,$other,true))) throw new MemberProfileException('One of you is already in a relationship.','RELATIONSHIP_ALREADY_ACTIVE',409);
                $pdo->prepare('UPDATE profile_relationship_requests SET status=?,updated_at=CURRENT_TIMESTAMP WHERE public_id=?')->execute([$wanted,$id]);
            }
        }
        if (!empty($tx['owned'])) database_transaction_commit($pdo,$tx);
    } catch(Throwable $e) {if (!empty($tx['owned'])) database_transaction_rollback($pdo,$tx);throw $e;}
    return profile_relationship_state($pdo,$actor);
}
