// An upload rejected before storage may be retried once after explicit consent.
export function outsideContentChallenge(error) {
    const payload = error?.responsePayload;
    const policy = payload?.confirmation;
    if (error?.details?.status !== 428
        || payload?.code !== 'OUTSIDE_CONTENT_CONFIRMATION_REQUIRED'
        || policy?.required !== true) return null;
    for (const key of ['title', 'body', 'checkbox']) {
        if (typeof policy.text?.[key] !== 'string'
            || !policy.text[key].trim() || policy.text[key].length > 5000) return null;
    }
    return policy;
}

export function confirmOutsideContent(policy) {
    return new Promise(resolve => {
        const previousFocus = document.activeElement;
        const dialog = document.createElement('dialog');
        dialog.setAttribute('aria-label', policy.text.title);
        dialog.style.cssText = 'box-sizing:border-box;width:min(520px,calc(100vw - 32px));max-height:calc(100dvh - 32px);overflow:auto;padding:24px;border:1px solid #737b94;border-radius:12px;background:#161923;color:#f5f6fa;';
        const form = document.createElement('form');
        const title = document.createElement('h2');
        title.textContent = policy.text.title;
        const body = document.createElement('p');
        body.textContent = policy.text.body;
        body.style.lineHeight = '1.5';
        const label = document.createElement('label');
        label.style.cssText = 'display:flex;gap:12px;align-items:flex-start;margin:20px 0;';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.required = true;
        checkbox.style.cssText = 'width:20px;height:20px;flex:0 0 20px;margin:2px 0;';
        const statement = document.createElement('span');
        statement.textContent = policy.text.checkbox;
        label.append(checkbox, statement);
        const actions = document.createElement('div');
        actions.style.cssText = 'display:flex;gap:12px;justify-content:flex-end;flex-wrap:wrap;';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = 'Cancel';
        const submit = document.createElement('button');
        submit.type = 'submit';
        submit.textContent = 'Confirm and continue';
        submit.disabled = true;
        for (const button of [cancel, submit]) {
            button.style.cssText = 'padding:10px 16px;border:1px solid #737b94;border-radius:6px;font:inherit;';
        }
        actions.append(cancel, submit);
        form.append(title, body, label, actions);
        dialog.append(form);
        let finished = false;
        const finish = accepted => {
            if (finished) return;
            finished = true;
            dialog.remove();
            if (previousFocus?.isConnected) previousFocus.focus();
            resolve(accepted);
        };
        checkbox.addEventListener('change', () => { submit.disabled = !checkbox.checked; });
        cancel.addEventListener('click', () => finish(false));
        dialog.addEventListener('cancel', event => { event.preventDefault(); finish(false); });
        dialog.addEventListener('close', () => finish(false));
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (checkbox.checked) finish(true);
        });
        document.body.append(dialog);
        try {
            dialog.showModal();
            cancel.focus();
        } catch {
            finish(false);
        }
    });
}

export async function postOutsideContentForm(client, path, form, options = {}, {
    confirm = confirmOutsideContent,
    createId = () => globalThis.crypto.randomUUID(),
} = {}) {
    const mayConfirm = form.get('outside_content_confirmed') !== '1';
    try {
        return await client.postForm(path, form, {
            ...options,
            shouldReportFailure: error => {
                if (mayConfirm && outsideContentChallenge(error)) return false;
                return options.shouldReportFailure?.(error) !== false;
            },
        });
    } catch (error) {
        const policy = mayConfirm ? outsideContentChallenge(error) : null;
        if (!policy) throw error;
        if (await confirm(policy) !== true) {
            const cancelled = new Error('Upload cancelled.');
            cancelled.code = 'OUTSIDE_CONTENT_CANCELLED';
            throw cancelled;
        }
        form.set('outside_content_confirmed', '1');
        form.set('outside_content_confirmation_id', createId());
        // Do not replay timeouts, network errors, or another rejected confirmation.
        return client.postForm(path, form, options);
    }
}
