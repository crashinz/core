/******************************************************************************
 * Chat Runtime Framework for ChatSpace
 * ---------------------------------------------------------------------------
 * Owner: Avatar Runtime
 * Build: 000044 Part 6
 * Purpose: Own the relationship-management interface and mutation lifecycle.
 ******************************************************************************/

const FOCUSABLE_SELECTOR = [
    "button:not([disabled]):not([hidden])",
    "select:not([disabled]):not([hidden])",
    "input:not([disabled]):not([hidden])",
    "[href]:not([hidden])",
    "[tabindex]:not([tabindex='-1']):not([hidden])"
].join(",");

export class AvatarRelationshipManagementService {

    #runtime;
    #context = null;
    #requests = Object.freeze([]);
    #relationshipId = "";
    #projection = null;
    #previousFocus = null;
    #bindings = null;
    #unsubscribeRelationship = null;
    #refreshTimer = null;
    #refreshPromise = null;
    #pendingMutation = null;
    #confirmation = null;
    #openCount = 0;
    #refreshCount = 0;
    #mutationCount = 0;

    constructor(runtime) {
        this.#runtime = runtime;
    }

    initialize() {
        this.#unsubscribeRelationship =
            this.#runtime.relationships.observeRelationshipEvents(() => {
                this.#syncLauncher();
                if (this.isOpen()) this.#render();
            });
    }

    destroy() {
        this.close({ restoreFocus: false });
        this.#bindings?.abort();
        this.#bindings = null;
        this.#unsubscribeRelationship?.();
        this.#unsubscribeRelationship = null;
        if (this.#refreshTimer !== null) clearTimeout(this.#refreshTimer);
        this.#refreshTimer = null;
        this.#refreshPromise = null;
        this.#pendingMutation = null;
        this.#confirmation = null;
        this.#context = null;
        this.#requests = Object.freeze([]);
        this.#projection = null;
        this.#relationshipId = "";
    }

    configure(context = {}) {
        this.#bindings?.abort();
        this.#bindings = new AbortController();
        this.#context = context;
        const signal = this.#bindings.signal;
        const modal = this.#element("relationship-management-modal");
        const close = this.#element("relationship-management-close");
        const launcher = this.#element("relationship-manage-btn");
        const joinPolicy = this.#element("relationship-management-join-policy");
        const leave = this.#element("relationship-management-leave");
        const dissolve = this.#element("relationship-management-dissolve");
        const confirmCancel = this.#element("relationship-management-confirm-cancel");
        const confirmAccept = this.#element("relationship-management-confirm-accept");
        const spacing = this.#element("relationship-management-spacing");
        const spacingReset = this.#element("relationship-management-spacing-reset");
        const formation = this.#element("relationship-management-formation");
        const transition = this.#element("relationship-management-transition");
        const joinRole = this.#element("relationship-management-join-role");
        const joinHost = this.#element("relationship-management-join-host");
        const inviteRole = this.#element("relationship-management-invite-role");
        const requestJoin = this.#element("relationship-management-request-join");
        const invite = this.#element("relationship-management-invite");
        close?.addEventListener("click", () => this.close(), { signal });
        launcher?.addEventListener("click", () => this.openCurrent(), { signal });
        modal?.addEventListener("click", event => {
            if (event.target === modal) {
                this.close();
                return;
            }
            const action = event.target.closest?.("[data-relationship-action]");
            if (action) this.#handleAction(action);
        }, { signal });
        modal?.addEventListener("keydown", event => this.#handleModalKeydown(event), { signal });
        joinPolicy?.addEventListener("change", event => {
            this.#mutate({
                action: "set_join_policy",
                join_policy: String(event.target.value || "")
            });
        }, { signal });
        leave?.addEventListener("click", () => this.#confirm({
            title: "Leave relationship?",
            message: "You will leave this relationship and lose access to its group chat.",
            action: "leave",
            payload: { action: "leave" }
        }), { signal });
        dissolve?.addEventListener("click", () => this.#confirm({
            title: "Dissolve relationship?",
            message: "This removes every member and permanently closes the relationship group.",
            action: "dissolve",
            payload: { action: "dissolve" }
        }), { signal });
        confirmCancel?.addEventListener("click", () => this.#cancelConfirmation(), { signal });
        confirmAccept?.addEventListener("click", () => {
            const confirmation = this.#confirmation;
            this.#cancelConfirmation({ restoreFocus: false });
            if (confirmation) this.#mutate(confirmation.payload);
        }, { signal });
        spacing?.addEventListener("input", event => {
            const value = Math.max(0, Math.min(64, Number(event.target.value || 0)));
            const output = this.#element("relationship-management-spacing-value");
            if (output) output.value = `${value} px`;
        }, { signal });
        spacing?.addEventListener("change", event => {
            this.#configureCurrent(
                this.#projection?.normalMembers?.map(member => member.participantId) || [],
                Number(event.target.value || 0)
            );
        }, { signal });
        spacingReset?.addEventListener("click", () => {
            this.#configureCurrent(
                this.#projection?.normalMembers?.map(member => member.participantId) || [],
                0
            );
        }, { signal });
        formation?.addEventListener("change", event => {
            this.#configureCurrent(
                this.#projection?.normalMembers?.map(member => member.participantId) || [],
                this.#projection?.rowOptions?.rowSpacing || 0,
                String(event.target.value || "horizontal-row")
            );
        }, { signal });
        transition?.addEventListener("change", event => {
            this.#configureCurrent(
                this.#projection?.normalMembers?.map(member => member.participantId) || [],
                this.#projection?.rowOptions?.rowSpacing || 0,
                this.#projection?.rowOptions?.formation || "horizontal-row",
                String(event.target.value || "snap")
            );
        }, { signal });
        joinRole?.addEventListener("change", () => this.#syncSeatFormControls(), { signal });
        joinHost?.addEventListener("change", () => this.#syncSeatFormControls(), { signal });
        inviteRole?.addEventListener("change", () => this.#syncSeatFormControls(), { signal });
        requestJoin?.addEventListener("click", () => this.#submitJoinRequest(), { signal });
        invite?.addEventListener("click", () => this.#submitInvitation(), { signal });
        this.#syncLauncher();
    }

    seed({ requests = [] } = {}) {
        this.#requests = Object.freeze(Array.from(requests || []).map(request => Object.freeze({ ...request })));
        this.#syncLauncher();
        if (this.isOpen()) this.#render();
    }

    async refresh({ render = true } = {}) {
        if (this.#refreshPromise) return this.#refreshPromise;
        this.#refreshPromise = (async () => {
            const response = await this.#context?.fetchManagementState?.({
                relationshipId: this.#relationshipId
            });
            if (response?.relationship) {
                this.#runtime.relationships.upsertPersistedRelationship(response.relationship);
                this.#relationshipId = String(response.relationship.id || response.relationship.relationship_id || this.#relationshipId);
            }
            this.seed({ requests: response?.requests || [] });
            this.#refreshCount += 1;
            if (render && this.isOpen()) this.#render();
            return response || null;
        })().finally(() => {
            this.#refreshPromise = null;
        });
        return this.#refreshPromise;
    }

    async openForParticipant(participantId, source = "avatar-context") {
        const relationship = this.#runtime.relationships.relationshipForParticipant(participantId);
        if (!relationship) return false;
        return this.open({ relationshipId: relationship.id, source });
    }

    async openForRelationship(relationshipId, source = "relationship-tab") {
        return this.open({ relationshipId, source });
    }

    async openCurrent(source = "relationship-launcher") {
        const viewerId = Number(this.#context?.getConfig?.()?.myParticipantId || 0);
        const relationship = this.#runtime.relationships.relationshipForParticipant(viewerId);
        return this.open({ relationshipId: relationship?.id || "", source });
    }

    async open({ relationshipId = "", source = "relationship-management" } = {}) {
        this.#relationshipId = String(relationshipId || this.#relationshipId || "");
        try {
            await this.refresh({ render: false });
        } catch (error) {
            this.#context?.showError?.(error);
            return false;
        }
        this.#projection = this.#buildProjection();
        if (!this.#projection) return false;
        const modal = this.#element("relationship-management-modal");
        if (!modal) return false;
        this.#previousFocus = this.#context?.document?.activeElement || null;
        modal.classList.add("open");
        modal.setAttribute("aria-hidden", "false");
        modal.dataset.openSource = String(source);
        this.#render();
        this.#element("relationship-management-close")?.focus();
        this.#openCount += 1;
        this.#context?.recordDiagnostic?.({ event: "relationship-management-opened", source });
        return true;
    }

    close({ restoreFocus = true } = {}) {
        const modal = this.#element("relationship-management-modal");
        if (!modal?.classList.contains("open")) return false;
        modal.classList.remove("open");
        modal.setAttribute("aria-hidden", "true");
        delete modal.dataset.openSource;
        delete modal.dataset.relationshipId;
        this.#cancelConfirmation({ restoreFocus: false });
        this.#projection = null;
        if (restoreFocus && this.#previousFocus?.isConnected) this.#previousFocus.focus();
        this.#previousFocus = null;
        this.#context?.recordDiagnostic?.({ event: "relationship-management-closed" });
        return true;
    }

    isOpen() {
        return Boolean(this.#element("relationship-management-modal")?.classList.contains("open"));
    }

    handleRemoteRelationship(payload = {}) {
        const action = String(payload.action || "");
        const relationshipId = String(payload.relationship_id || "");
        if (this.#relationshipId && relationshipId && relationshipId !== this.#relationshipId) return;
        if (["relationship-dissolved", "member-left", "member-removed"].includes(action)) {
            const viewerId = Number(this.#context?.getConfig?.()?.myParticipantId || 0);
            const targetId = Number(payload.target_participant_id || 0);
            if (action === "relationship-dissolved" || targetId === viewerId) this.close();
        }
        if (this.#refreshTimer !== null) clearTimeout(this.#refreshTimer);
        this.#refreshTimer = setTimeout(() => {
            this.#refreshTimer = null;
            this.refresh().catch(error => this.#context?.showError?.(error));
        }, 0);
    }

    projection() {
        return this.#projection;
    }

    getDiagnostics() {
        return Object.freeze({
            owner: "AvatarRuntime",
            build: "000044 Part 6",
            configured: Boolean(this.#context),
            open: this.isOpen(),
            relationshipId: this.#relationshipId || null,
            requestCount: this.#requests.length,
            pendingMutation: this.#pendingMutation,
            openCount: this.#openCount,
            refreshCount: this.#refreshCount,
            mutationCount: this.#mutationCount
        });
    }

    #buildProjection() {
        const config = this.#context?.getConfig?.() || {};
        const viewerId = Number(config.myParticipantId || 0);
        const relationship = this.#relationshipId
            ? this.#runtime.relationships.relationshipById(this.#relationshipId)
            : this.#runtime.relationships.relationshipForParticipant(viewerId);
        if (relationship) this.#relationshipId = relationship.id;
        return this.#runtime.relationships.relationshipManagementProjection(
            relationship,
            {
                viewerParticipantId: viewerId,
                participants: this.#runtime.state,
                requests: this.#requests
            }
        );
    }

    #render() {
        const projection = this.#buildProjection();
        this.#projection = projection;
        if (!projection) {
            this.close();
            return;
        }
        if (projection.relationshipStatus === "active" && !projection.viewer.active
            && !projection.requests.length && !projection.actions.requestJoin) {
            this.close();
            return;
        }
        const modal = this.#element("relationship-management-modal");
        if (modal) modal.dataset.relationshipId = projection.relationshipId;
        const summary = this.#element("relationship-management-summary");
        const members = this.#element("relationship-management-members");
        const requests = this.#element("relationship-management-requests");
        const requestSection = this.#element("relationship-management-request-section");
        const joinSection = this.#element("relationship-management-join-section");
        const inviteSection = this.#element("relationship-management-invite-section");
        const seatSection = this.#element("relationship-management-seat-section");
        const seats = this.#element("relationship-management-seats");
        const settings = this.#element("relationship-management-settings");
        const footer = this.#element("relationship-management-footer");
        const joinPolicy = this.#element("relationship-management-join-policy");
        const leave = this.#element("relationship-management-leave");
        const dissolve = this.#element("relationship-management-dissolve");
        const order = this.#element("relationship-management-order");
        const spacing = this.#element("relationship-management-spacing");
        const spacingValue = this.#element("relationship-management-spacing-value");
        const spacingReset = this.#element("relationship-management-spacing-reset");
        const formation = this.#element("relationship-management-formation");
        const formationStatus = this.#element("relationship-management-formation-status");
        const transition = this.#element("relationship-management-transition");
        if (summary) {
            const pendingInvitation = projection.requests.some(request =>
                request.type === "invitation" && request.actions.accept
            );
            const pendingJoinRequest = projection.requests.some(request =>
                request.type === "join-request" && request.actions.cancel
            );
            summary.textContent = projection.viewer.active
                ? `${projection.members.length} members · ${this.#policyLabel(projection.joinPolicy)}`
                : pendingInvitation
                    ? "Pending relationship invitation"
                    : pendingJoinRequest
                        ? "Membership request pending"
                        : "Request relationship membership";
        }
        members?.replaceChildren(...projection.members.map(member => this.#memberRow(member, projection)));
        requests?.replaceChildren(...projection.requests.map(request => this.#requestRow(request, projection)));
        seats?.replaceChildren(...projection.lapSeats.map(seat => this.#seatRow(seat)));
        order?.replaceChildren(...projection.normalMembers.map((member, index) =>
            this.#orderRow(member, index, projection)
        ));
        if (requestSection) requestSection.hidden = projection.requests.length === 0;
        if (joinSection) joinSection.hidden = !projection.actions.requestJoin;
        if (inviteSection) inviteSection.hidden = !projection.actions.invite;
        if (seatSection) seatSection.hidden = projection.lapSeats.length === 0;
        if (settings) settings.hidden = !projection.viewer.active;
        if (footer) footer.hidden = !projection.viewer.active;
        if (joinPolicy) {
            joinPolicy.value = projection.joinPolicy;
            joinPolicy.disabled = this.#isPending() || !projection.actions.setJoinPolicy;
        }
        if (leave) leave.disabled = this.#isPending() || !projection.actions.leave;
        if (dissolve) dissolve.disabled = this.#isPending() || !projection.actions.dissolve;
        if (spacing) {
            spacing.value = String(projection.rowOptions.rowSpacing);
            spacing.disabled = this.#isPending() || !projection.actions.configurePosition;
        }
        if (spacingValue) spacingValue.value = `${projection.rowOptions.rowSpacing} px`;
        if (spacingReset) {
            spacingReset.disabled = this.#isPending()
                || !projection.actions.configurePosition
                || projection.rowOptions.rowSpacing === 0;
        }
        if (formation) {
            formation.value = projection.rowOptions.formation;
            Array.from(formation.options).forEach(option => {
                const definition = projection.rowOptions.formationOptions
                    .find(item => item.id === option.value);
                option.disabled = !definition?.available;
            });
            formation.disabled = this.#isPending() || !projection.actions.configurePosition;
        }
        if (formationStatus) {
            formationStatus.textContent = projection.rowOptions.formationFallbackReason
                ? `Currently using ${this.#formationLabel(projection.rowOptions.effectiveFormation)}`
                : `Currently using ${this.#formationLabel(projection.rowOptions.formation)}`;
        }
        if (transition) {
            transition.value = projection.rowOptions.transition;
            transition.disabled = this.#isPending() || !projection.actions.configurePosition;
        }
        if (this.#isPending()) this.#setStatus("Saving relationship changes...");
        this.#renderSeatForms(projection);
        this.#syncLauncher(projection);
    }

    #memberRow(member, projection) {
        const document = this.#context.document;
        const row = document.createElement("li");
        row.className = "relationship-member-row";
        row.dataset.participantId = String(member.participantId);
        const identity = document.createElement("div");
        const name = document.createElement("strong");
        name.textContent = member.isViewer ? `${member.displayName} (You)` : member.displayName;
        const detail = document.createElement("span");
        const role = member.relationshipRole === "lap"
            ? `Lap occupant · ${this.#sideLabel(member.lapSide)}`
            : `Normal · position ${projection.normalMembers.findIndex(item => item.participantId === member.participantId) + 1}`;
        const host = member.lapHostParticipantId
            ? projection.members.find(item => item.participantId === member.lapHostParticipantId)?.displayName
            : "";
        detail.textContent = `${role}${host ? ` · host ${host}` : ""}`;
        identity.append(name, detail);
        const status = document.createElement("div");
        status.className = "relationship-member-status";
        const permission = document.createElement("span");
        permission.className = `relationship-role-badge role-${member.permissionRole}`;
        permission.textContent = this.#permissionLabel(member.permissionRole);
        const presence = document.createElement("span");
        presence.className = `relationship-presence ${member.present ? "present" : "absent"}`;
        presence.textContent = member.present ? (member.renderable ? "Present" : "Present, loading") : "Away";
        status.append(permission, presence);
        const actions = document.createElement("div");
        actions.className = "relationship-member-actions";
        if (member.actions.promote) {
            actions.append(this.#actionButton("Promote", "promote_member", member.participantId));
        }
        if (member.actions.demote) {
            actions.append(this.#actionButton("Demote", "demote_member", member.participantId));
        }
        if (member.actions.remove) {
            actions.append(this.#actionButton("Remove", "remove_member", member.participantId, true));
        }
        if (member.actions.switchLapSide) {
            const seat = projection.lapSeats.find(item => item.hostParticipantId === member.lapHostParticipantId);
            const nextSide = member.lapSide === "bottom-left" ? "bottom-right" : "bottom-left";
            if (seat?.availableSides?.includes(nextSide)) {
                const button = this.#actionButton(`Switch to ${this.#sideLabel(nextSide)}`, "set_lap_side", member.participantId);
                button.dataset.lapSide = nextSide;
                actions.append(button);
            }
        }
        row.append(identity, status);
        if (actions.childElementCount) row.append(actions);
        return row;
    }

    #requestRow(request, projection) {
        const document = this.#context.document;
        const row = document.createElement("li");
        row.className = "relationship-request-row";
        const label = document.createElement("strong");
        label.textContent = request.type === "invitation"
            ? `${request.requesterName} invited ${request.targetName}`
            : `${request.requesterName} requested to join`;
        const detail = document.createElement("span");
        const host = request.requestedLapHostParticipantId
            ? projection.members.find(member => member.participantId === request.requestedLapHostParticipantId)?.displayName
            : "";
        const side = request.requestedLapSide ? ` · ${this.#sideLabel(request.requestedLapSide)}` : "";
        detail.textContent = `${request.requestedRelationshipRole === "lap" ? "Lap" : "Normal"}${host ? ` · host ${host}` : ""}${side} · ${request.status}`;
        const identity = document.createElement("div");
        identity.append(label, detail);
        const actions = document.createElement("div");
        actions.className = "relationship-request-actions";
        if (request.actions.accept && request.type === "invitation" && request.requestedRelationshipRole === "lap") {
            const seat = projection.lapSeats.find(item => item.hostParticipantId === request.requestedLapHostParticipantId);
            seat?.availableSides?.forEach(lapSide => {
                actions.append(this.#requestActionButton(`Accept ${this.#sideLabel(lapSide)}`, "accept_request", request, lapSide));
            });
        } else if (request.actions.accept) {
            actions.append(this.#requestActionButton("Accept", "accept_request", request));
        }
        if (request.actions.reject) actions.append(this.#requestActionButton("Reject", "reject_request", request));
        if (request.actions.cancel) actions.append(this.#requestActionButton("Cancel", "cancel_request", request));
        row.append(identity);
        if (actions.childElementCount) row.append(actions);
        return row;
    }

    #seatRow(seat) {
        const row = this.#context.document.createElement("li");
        row.className = "relationship-seat-row";
        const content = this.#context.document.createElement("div");
        const host = this.#context.document.createElement("strong");
        host.textContent = seat.hostName;
        content.append(host);
        ["bottom-left", "bottom-right"].forEach(side => {
            const line = this.#context.document.createElement("span");
            const occupant = seat.occupants[side];
            line.textContent = `${this.#sideLabel(side)}: ${occupant?.displayName || "Open"}`;
            content.append(line);
        });
        row.append(content);
        return row;
    }

    #orderRow(member, index, projection) {
        const document = this.#context.document;
        const row = document.createElement("div");
        row.className = "relationship-order-row";
        row.dataset.participantId = String(member.participantId);
        const label = document.createElement("span");
        label.textContent = `${index + 1}. ${member.displayName}`;
        const actions = document.createElement("div");
        actions.className = "relationship-order-actions";
        const lastIndex = projection.normalMembers.length - 1;
        [
            ["First", "order-first", index === 0],
            ["Left", "order-left", index === 0],
            ["Right", "order-right", index === lastIndex],
            ["Last", "order-last", index === lastIndex]
        ].forEach(([text, action, atBoundary]) => {
            const button = this.#actionButton(text, action, member.participantId);
            button.disabled = this.#isPending() || !projection.actions.reorder || atBoundary;
            button.setAttribute("aria-label", `${text}: ${member.displayName}`);
            actions.append(button);
        });
        row.append(label, actions);
        return row;
    }

    #actionButton(label, action, participantId, destructive = false) {
        const button = this.#context.document.createElement("button");
        button.type = "button";
        button.className = destructive ? "btn btn-danger" : "btn";
        button.textContent = label;
        button.dataset.relationshipAction = action;
        button.dataset.targetParticipantId = String(participantId);
        button.disabled = this.#isPending();
        return button;
    }

    #requestActionButton(label, action, request, lapSide = null) {
        const button = this.#actionButton(label, action, 0, action === "reject_request");
        button.dataset.requestId = request.id;
        button.dataset.relationshipId = request.relationshipId;
        button.dataset.relationshipVersion = String(request.relationshipVersion);
        if (lapSide) button.dataset.lapSide = lapSide;
        return button;
    }

    #handleAction(button) {
        if (this.#isPending()) return;
        const action = String(button.dataset.relationshipAction || "");
        const targetParticipantId = Number(button.dataset.targetParticipantId || 0);
        const requestId = String(button.dataset.requestId || "");
        const lapSide = String(button.dataset.lapSide || "");
        const payload = { action };
        if (targetParticipantId > 0) payload.target_participant_id = targetParticipantId;
        if (requestId) {
            payload.request_id = requestId;
            payload.relationship_id = String(button.dataset.relationshipId || "");
            payload.expected_version = Number(button.dataset.relationshipVersion || 0);
        }
        if (lapSide) payload.lap_side = lapSide;
        if (action === "set_lap_side") payload.operation_id = this.#operationId("lap-side");
        if (action.startsWith("order-")) {
            this.#reorder(targetParticipantId, action);
            return;
        }
        if (action === "remove_member") {
            const member = this.#projection?.members?.find(candidate =>
                candidate.participantId === targetParticipantId
            );
            this.#confirm({
                title: "Remove relationship member?",
                message: `${member?.displayName || "This member"} will lose relationship and group-chat access.`,
                action,
                payload
            });
            return;
        }
        this.#mutate(payload);
    }

    #renderSeatForms(projection) {
        const hostOptions = projection.lapSeats
            .filter(seat => seat.availableSides.length > 0)
            .map(seat => ({
                value: String(seat.hostParticipantId),
                label: seat.hostName
            }));
        const candidateOptions = projection.inviteCandidates.map(candidate => ({
            value: String(candidate.participantId),
            label: candidate.displayName
        }));
        this.#populateSelect(this.#element("relationship-management-join-host"), hostOptions);
        this.#populateSelect(this.#element("relationship-management-invite-host"), hostOptions);
        this.#populateSelect(this.#element("relationship-management-invite-target"), candidateOptions, "No eligible participants");
        this.#syncSeatFormControls();
    }

    #syncSeatFormControls() {
        const projection = this.#projection;
        if (!projection) return;
        const pending = this.#isPending();
        const joinRole = String(this.#element("relationship-management-join-role")?.value || "normal");
        const joinHost = this.#element("relationship-management-join-host");
        const joinSide = this.#element("relationship-management-join-side");
        const selectedHostId = Number(joinHost?.value || 0);
        const selectedSeat = projection.lapSeats.find(seat => seat.hostParticipantId === selectedHostId);
        const availableSides = selectedSeat?.availableSides || [];
        this.#populateSelect(
            joinSide,
            availableSides.map(side => ({ value: side, label: this.#sideLabel(side) })),
            "No available side"
        );
        if (joinHost) joinHost.disabled = pending || joinRole !== "lap" || projection.lapSeats.length === 0;
        if (joinSide) joinSide.disabled = pending || joinRole !== "lap" || availableSides.length === 0;
        const requestButton = this.#element("relationship-management-request-join");
        if (requestButton) {
            requestButton.disabled = pending || !projection.actions.requestJoin
                || (joinRole === "lap" && (!selectedHostId || availableSides.length === 0));
        }

        const inviteRole = String(this.#element("relationship-management-invite-role")?.value || "normal");
        const inviteHost = this.#element("relationship-management-invite-host");
        const inviteTarget = this.#element("relationship-management-invite-target");
        if (inviteHost) inviteHost.disabled = pending || inviteRole !== "lap" || projection.lapSeats.length === 0;
        const inviteButton = this.#element("relationship-management-invite");
        if (inviteButton) {
            inviteButton.disabled = pending || !projection.actions.invite || !Number(inviteTarget?.value || 0)
                || (inviteRole === "lap" && !Number(inviteHost?.value || 0));
        }
    }

    #submitJoinRequest() {
        if (this.#isPending() || !this.#projection?.actions.requestJoin) return;
        const role = String(this.#element("relationship-management-join-role")?.value || "normal");
        const payload = {
            action: "request_join",
            relationship_role: role
        };
        if (role === "lap") {
            payload.lap_host_participant_id = Number(this.#element("relationship-management-join-host")?.value || 0);
            payload.lap_side = String(this.#element("relationship-management-join-side")?.value || "");
            if (!payload.lap_host_participant_id || !payload.lap_side) return;
        }
        this.#mutate(payload);
    }

    #submitInvitation() {
        if (this.#isPending() || !this.#projection?.actions.invite) return;
        const role = String(this.#element("relationship-management-invite-role")?.value || "normal");
        const payload = {
            action: "invite",
            target_participant_id: Number(this.#element("relationship-management-invite-target")?.value || 0),
            relationship_role: role
        };
        if (!payload.target_participant_id) return;
        if (role === "lap") {
            payload.lap_host_participant_id = Number(this.#element("relationship-management-invite-host")?.value || 0);
            if (!payload.lap_host_participant_id) return;
        }
        this.#mutate(payload);
    }

    #populateSelect(select, options, emptyLabel = "No options") {
        if (!select) return;
        const current = String(select.value || "");
        const nodes = options.map(option => {
            const node = this.#context.document.createElement("option");
            node.value = option.value;
            node.textContent = option.label;
            return node;
        });
        if (!nodes.length) {
            const empty = this.#context.document.createElement("option");
            empty.value = "";
            empty.textContent = emptyLabel;
            nodes.push(empty);
        }
        select.replaceChildren(...nodes);
        if (options.some(option => option.value === current)) select.value = current;
    }

    #sideLabel(side) {
        return side === "bottom-left" ? "Left side" : "Right side";
    }

    #reorder(participantId, action) {
        const order = this.#projection?.normalMembers?.map(member => member.participantId) || [];
        const currentIndex = order.indexOf(Number(participantId));
        if (currentIndex < 0 || order.length < 2) return;
        const next = order.slice();
        const [selected] = next.splice(currentIndex, 1);
        const nextIndex = action === "order-first"
            ? 0
            : action === "order-last"
                ? next.length
                : action === "order-left"
                    ? Math.max(0, currentIndex - 1)
                    : Math.min(next.length, currentIndex + 1);
        next.splice(nextIndex, 0, selected);
        if (next.every((value, index) => value === order[index])) return;
        this.#configureCurrent(next, this.#projection.rowOptions.rowSpacing);
    }

    #configureCurrent(
        normalMemberOrder,
        rowSpacing,
        formation = this.#projection?.rowOptions?.formation || "horizontal-row",
        transition = this.#projection?.rowOptions?.transition || "snap"
    ) {
        if (this.#isPending() || !this.#projection?.actions.configurePosition) return false;
        const proposal = this.#runtime.coordinator?.relationshipConfigurationProposal({
            relationshipId: this.#projection.relationshipId,
            normalMemberOrder,
            rowSpacing,
            formation,
            transition
        });
        if (!proposal) {
            this.#setStatus("The current relationship layout could not be configured.");
            return false;
        }
        this.#mutate({
            action: "configure",
            operation_id: this.#operationId(),
            normal_member_order: proposal.normalMemberOrder,
            options: proposal.options,
            positions: proposal.positions
        });
        return true;
    }

    #formationLabel(formation) {
        return formation === "bottom-center-trio"
            ? "Bottom-Center Trio"
            : formation === "top-center-trio"
                ? "Top-Center Trio"
                : formation === "grid"
                    ? "Grid"
                    : "Horizontal Row";
    }

    #operationId(prefix = "config") {
        const uuid = globalThis.crypto?.randomUUID?.();
        return uuid
            ? `${prefix}-${uuid}`
            : `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    }

    #confirm({ title, message, action, payload }) {
        if (this.#isPending()) return;
        this.#confirmation = Object.freeze({
            title,
            message,
            action,
            payload: Object.freeze({ ...payload })
        });
        const confirmation = this.#element("relationship-management-confirm");
        if (confirmation) confirmation.hidden = false;
        const titleElement = this.#element("relationship-management-confirm-title");
        const messageElement = this.#element("relationship-management-confirm-message");
        if (titleElement) titleElement.textContent = title;
        if (messageElement) messageElement.textContent = message;
        this.#element("relationship-management-confirm-accept")?.focus();
    }

    #cancelConfirmation({ restoreFocus = true } = {}) {
        const hadConfirmation = Boolean(this.#confirmation);
        this.#confirmation = null;
        const confirmation = this.#element("relationship-management-confirm");
        if (confirmation) confirmation.hidden = true;
        if (hadConfirmation && restoreFocus) this.#element("relationship-management-close")?.focus();
    }

    async #mutate(payload = {}) {
        if (this.#isPending()) return false;
        const projection = this.#projection || this.#buildProjection();
        const action = String(payload.action || "");
        const relationshipId = String(payload.relationship_id || projection?.relationshipId || "");
        const expectedVersion = Number(payload.expected_version || projection?.relationshipVersion || 0);
        if (!action || !relationshipId || expectedVersion < 1) return false;
        this.#pendingMutation = action;
        this.#render();
        try {
            const response = await this.#context?.mutateRelationship?.({
                ...payload,
                action,
                relationship_id: relationshipId,
                expected_version: expectedVersion
            });
            this.#mutationCount += 1;
            if (response?.relationship) {
                this.#runtime.coordinator?.reconcileRemoteRelationship({
                    action: this.#eventAction(action),
                    relationship_id: String(response.relationship.id || response.relationship.relationship_id || relationshipId),
                    relationship_version: Number(response.relationship.version || expectedVersion),
                    relationship_status: String(response.relationship.status || "active"),
                    relationship: response.relationship,
                    configuration: response.configuration || null,
                    positions: response.positions || []
                });
            }
            await this.refresh({ render: false });
            this.#pendingMutation = null;
            const refreshed = this.#buildProjection();
            if (!refreshed || (!refreshed.viewer.active && !refreshed.requests.length && !refreshed.actions.requestJoin)) {
                this.close();
            } else {
                this.#render();
                this.#setStatus("Relationship updated.");
            }
            this.#context?.recordDiagnostic?.({
                event: "relationship-management-mutated",
                action,
                relationshipId,
                relationshipVersion: Number(response?.relationship?.version || expectedVersion)
            });
            return true;
        } catch (error) {
            this.#pendingMutation = null;
            const status = Number(error?.details?.status || 0);
            if (status === 409) {
                try {
                    await this.refresh({ render: false });
                } catch (refreshError) {
                    void refreshError;
                }
                this.#setStatus("The relationship changed. Review the latest state and try again.");
            } else if (status === 403) {
                this.#setStatus("That relationship action is no longer available.");
            } else {
                this.#setStatus("Relationship changes could not be saved.");
            }
            if (this.isOpen()) this.#render();
            this.#context?.showError?.(error);
            this.#context?.recordDiagnostic?.({
                event: "relationship-management-mutation-failed",
                action,
                relationshipId,
                status: status || null
            });
            return false;
        }
    }

    #eventAction(action) {
        return ({
            set_join_policy: "join-policy-changed",
            request_join: "request-created",
            invite: "invitation-created",
            accept_request: "request-accepted",
            reject_request: "request-rejected",
            cancel_request: "request-cancelled",
            promote_member: "permission-changed",
            demote_member: "permission-changed",
            remove_member: "member-removed",
            leave: "member-left",
            dissolve: "relationship-dissolved",
            configure: "configuration-updated",
            set_lap_side: "lap-side-changed"
        })[action] || "relationship-updated";
    }

    #isPending() {
        return Boolean(this.#pendingMutation);
    }

    #setStatus(message) {
        const status = this.#element("relationship-management-status");
        if (status) status.textContent = String(message || "");
    }

    #syncLauncher(projection = null) {
        const launcher = this.#element("relationship-manage-btn");
        if (!launcher) return;
        const config = this.#context?.getConfig?.() || {};
        const viewerId = Number(config.myParticipantId || 0);
        const current = projection || this.#runtime.relationships.relationshipManagementProjection(
            this.#runtime.relationships.relationshipForParticipant(viewerId),
            { viewerParticipantId: viewerId, participants: this.#runtime.state, requests: this.#requests }
        );
        launcher.hidden = !current;
        const pendingCount = current?.requests?.filter(request =>
            request.actions.accept || request.actions.reject || request.actions.cancel
        ).length || 0;
        launcher.textContent = pendingCount > 0
            ? `Relationship (${pendingCount})`
            : "Manage Relationship";
        launcher.setAttribute("aria-label", pendingCount > 0
            ? `Manage relationship, ${pendingCount} pending request${pendingCount === 1 ? "" : "s"}`
            : "Manage relationship");
    }

    #handleModalKeydown(event) {
        if (event.key === "Escape") {
            event.preventDefault();
            this.close();
            return;
        }
        if (event.key !== "Tab") return;
        const modal = this.#element("relationship-management-modal");
        const focusable = Array.from(modal?.querySelectorAll(FOCUSABLE_SELECTOR) || [])
            .filter(element => !element.hidden
                && !element.closest("[hidden]")
                && element.getAttribute("aria-hidden") !== "true");
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && this.#context.document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && this.#context.document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    #permissionLabel(role) {
        return role === "creator" ? "Creator" : role === "manager" ? "Manager" : "Member";
    }

    #policyLabel(policy) {
        return policy === "open" ? "Open joining" : policy === "closed" ? "Invitations only" : "Join approval required";
    }

    #element(id) {
        return this.#context?.document?.getElementById(id) || null;
    }

}

export default AvatarRelationshipManagementService;
