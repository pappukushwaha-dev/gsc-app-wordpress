<?php
// partials/ticket-submit-popup.php
// NOTE: this partial only contains the modal and helper JS logic.
?>

<div id="global-ticket-modal" class="fixed inset-0 bg-black/40 hidden items-center justify-center bg-cstm-black-40 backdrop-blur-sm shadow-lg z-50">
    <div class="relative bg-white dark:bg-neutral-900 rounded-2xl shadow-xl max-w-2xl w-full p-6 max-h-[90vh] overflow-y-auto">

        <div class="flex justify-between items-center mb-4">
            <h5 class="text-lg font-semibold"></h5>
            <button type="button" id="global-ticket-close" class="btn-close">✕</button>
        </div>

        <div id="ticket-form-state" class="space-y-3">
            <h5 class="text-lg font-semibold absolute" style="top: 20px;">Create Request</h5>
            <div id="modal-error-alert" class="hidden bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-4 text-sm flex items-center gap-2">
                <iconify-icon icon="mdi:alert-circle" class="text-xl"></iconify-icon>
                <span id="modal-error-message"></span>
            </div>

            <label class="text-base dark:text-white">Subject</label>
            <input type="text" id="global-ticket-subject" class="form-input w-full form-control border !mb-6 !mt-2" placeholder="Subject">

            <p class="text-sm !mb-6">
                <strong>Owner Email:</strong>
                <span id="global-ticket-owner-email" class="font-normal text-neutral-500 dark:text-neutral-400">Loading...</span>
            </p>

            <label class="text-base !mt-6 dark:text-white">Initial Message</label>
            <textarea id="global-ticket-message" class="form-control form-input w-full !mb-6 !mt-2" rows="3" placeholder="Type initial message"></textarea>

            <label class="text-base">Attachment (Optional)</label>
            <input type="file" id="global-ticket-file" class="block w-full text-sm text-neutral-500 border rounded-lg cursor-pointer">

            <div class="flex justify-center gap-2 pt-2">
                <button id="global-ticket-submit" class="btn btn-cstm-primary">Submit Request</button>
            </div>
        </div>

        <div id="ticket-success-state" class="hidden text-center">
            <iconify-icon icon="mdi:check-circle-outline" class="text-success-600 text-[70px] mb-4 -mt-[24px]"></iconify-icon>
            <h3 class="text-xl font-semibold mb-2">Request Submitted Successfully</h3>
            <p class="text-sm text-neutral-600 dark:text-neutral-400 mb-4">
                We’ve received your request. Our support team will get back to you within <strong>24 hours</strong>.
                <br>
                <span class="block mt-1">Support is unavailable on weekends.</span>
            </p>
            <div class="flex justify-center items-center gap-4">
                    <?php
                        $isSetupWizard = str_contains($_SERVER['REQUEST_URI'], '/setup-wizard.php');

                        $instructionUrl = $isSetupWizard
                            ? 'wizard-instructions.php?instance_id=' . urlencode($instanceId)
                            : 'instructions.php?instance_id=' . urlencode($instanceId);
                        ?>
                    <a href="<?= $instructionUrl ?>"  class="btn btn-cstm-primary flex items-center gap-2"> <iconify-icon icon="solar:document-text-outline" class="text-xl"></iconify-icon>Instruction</a>
                    <a href="pricing.php" target="_blank" class="btn btn-cstm-primary flex items-center gap-2"><iconify-icon icon="mdi:crown-outline" class="text-xl"></iconify-icon> Upgrade</a>
            </div>
        </div>
    </div>
</div>

<script>
    window.CURRENT_SITE_URL = <?= json_encode($siteUrl ?? '', JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
    (function() {
        window.TicketPopup = window.TicketPopup || {};

        const $id = (id) => document.getElementById(id);

        const modal = $id('global-ticket-modal');
        const formState = $id('ticket-form-state');
        const successState = $id('ticket-success-state');
        const errorAlert = $id('modal-error-alert');
        const errorMessage = $id('modal-error-message');
        const btnSubmit = $id('global-ticket-submit');

        const fldSubject = $id('global-ticket-subject');
        const fldMessage = $id('global-ticket-message');
        const fldFile = $id('global-ticket-file');
        const ownerEmailEl = $id('global-ticket-owner-email');

        // Internal Alert Logic
        function ShowModalError(msg) {
            if (!errorAlert || !errorMessage) return;
            errorMessage.textContent = msg;
            errorAlert.classList.remove('hidden');
            $id('global-ticket-modal').children[0].scrollTop = 0;
        }

        function hideModalError() {
            errorAlert?.classList.add('hidden');
        }

        function showModal() {
            if (!modal) return;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function hideModal() {
            if (!modal) return;
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }

        function clearForm() {
            if (fldSubject) fldSubject.value = '';
            if (fldMessage) fldMessage.value = '';
            if (fldFile) fldFile.value = null;
            hideModalError();
            formState.classList.remove('hidden');
            successState.classList.add('hidden');
        }

        // Exposed Methods
        window.TicketPopup.open = function() {
            clearForm();
            showModal();
        };

        window.TicketPopup.setOwnerEmail = (email) => {
            if (ownerEmailEl) ownerEmailEl.textContent = email || 'N/A';
        };

        window.TicketPopup.setInstanceId = (instId) => {
            window.TicketPopup.instanceId = instId;
        };

        // UI Event Listeners
        $id('global-ticket-close')?.addEventListener('click', hideModal);
        $id('ticket-success-close')?.addEventListener('click', hideModal);

        if (btnSubmit) {
            btnSubmit.addEventListener('click', async function() {
                const subject = fldSubject?.value.trim();
                const message = fldMessage?.value.trim();
                const file = fldFile?.files?.[0];
                let attachmentInfo = null;

                hideModalError();

                if (!subject || !message) {
                    showAlert('Subject and message required.', 'error');
                    return;
                }

                btnSubmit.disabled = true;
                const originalBtnText = btnSubmit.textContent;
                btnSubmit.textContent = 'Processing...';

                try {
                    // 1) Create Ticket
                    const createRes = await fetch('./api/create_ticket.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            instance_id: window.TicketPopup.instanceId || null,
                            subject: subject,
                            owner_email: ownerEmailEl.textContent,
                            message: message
                        })
                    });
                    const createJson = await createRes.json();

                    if (!createJson.success || !createJson.ticket_id) {
                        throw new Error(createJson.error || createJson.message || 'Ticket creation failed');
                    }
                    const ticketId = createJson.ticket_id;

                    // 2) Post Message
                    const msgRes = await fetch('./api/post_ticket_message.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            instance_id: window.TicketPopup.instanceId || null,
                            ticket_id: ticketId,
                            message: message,
                            is_internal: 0
                        })
                    });
                    const msgJson = await msgRes.json();
                    if (!msgJson.success) throw new Error('Ticket 3 created, but message failed to post.');

                    const messageId = msgJson.message_id || null;

                    // 3) File Upload
                    if (file && messageId) {
                        const fd = new FormData();
                        fd.append('instance_id', window.TicketPopup.instanceId || '');
                        fd.append('ticket_id', ticketId);
                        fd.append('message_id', messageId);
                        fd.append('file', file);

                        const upRes = await fetch('./api/upload_ticket_attachment.php', {
                            method: 'POST',
                            body: fd
                        });
                        const upJson = await upRes.json();
                        if (upJson.success) attachmentInfo = upJson;
                    }

                    // 4) Send Email
                    await fetch('./api/send_ticket_email.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            ticket_id: ticketId,
                            message: message,
                            website_url: window.CURRENT_SITE_URL || '',
                            owner_email: ownerEmailEl.textContent,
                            attachment: attachmentInfo
                        })
                    });

                    // FINAL STEP: SWITCH TO SUCCESS STATE INSIDE MODAL
                    formState.classList.add('hidden');
                    successState.classList.remove('hidden');

                    if (typeof window.TicketPopup.onSuccess === 'function') {
                        window.TicketPopup.onSuccess({
                            ticket_id: ticketId
                        });
                    }

                } catch (err) {
                    console.error('Ticket Error:', err);
                    ShowModalError(err.message || 'A network error occurred.');
                } finally {
                    btnSubmit.disabled = false;
                    btnSubmit.textContent = originalBtnText;
                }
            });
        }

        // Global override so any other call to ShowAlert uses the modal error instead
        window.ShowAlert = ShowModalError;
    })();
</script>