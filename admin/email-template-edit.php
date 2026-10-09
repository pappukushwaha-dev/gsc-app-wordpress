<?php
// Include core files for session and authentication logic
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';

// Start the database-backed session
SessionManager::startDatabaseSession();

// Redirect to login if the user is not authenticated
if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

$title = 'Email Template Editor';
$subTitle = 'Create and Edit Email Templates';

// Fetch categories from the database
$query = "SELECT * FROM email_categories";
$categoriesResult = $pdo->query($query);
$categories = $categoriesResult->fetchAll(PDO::FETCH_ASSOC);

// Fetch the template data if editing
$templateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$template = null;
if ($templateId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE id = ?");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
}

// If no template is found, redirect to the email templates list page
if (!$template && $templateId > 0) {
    header('Location: email-templates.php');
    exit;
}
?>

<?php include './partials/layouts/layoutTop.php' ?>

<div class="card border-none">

    <div class="col-span-12">
        <div class="card-header border-b border-neutral-200 dark:border-neutral-600 px-6 py-4 text-end">
            <a href="email-templates.php" class="btn btn-cstm-primary flex justify-self-end items-center gap-2">
                <iconify-icon icon="icon-park-outline:back"></iconify-icon>
                Back
            </a>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-6 p-6">
        <div class="col-span-12 lg:col-span-8">
            <div class="card rounded-lg border border-gray-200 shadow-sm p-6 dark:border-gray-600">
                <div class="mb-6">
                    <h2 class="text-base text-neutral-600 dark:text-neutral-200 flex items-center gap-2">
                        <i class="far fa-file-alt text-gray-600 dark:text-gray-200 text-base"></i>
                        Template Properties & Content
                    </h2>
                </div>

                <div class="space-y-4">
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label for="templateName" class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Template Name</label>
                            <input type="text" class="form-control rounded-lg" id="templateName" value="<?= htmlspecialchars($template['name']) ?>" placeholder="e.g., Order Confirmation">
                        </div>

                        <div class="space-y-2">
                            <label class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Category</label>
                            <select class="form-control rounded-lg mt-0" id="category">
                                <option value="">Select category...</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>" <?= $category['id'] == $template['category_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">
                            Email Subject
                            <span class="text-xs text-gray-500">(Use tokens like <span class="text-cstm-primary drk-text-cstm-primary">\{\{name\}\}</span>)</span>
                        </label>
                        <input type="text" class="form-control rounded-lg" id="emailSubject" value="<?= htmlspecialchars($template['subject']) ?>" placeholder="Your {{company_name}} order is confirmed!">
                    </div>

                    <div class="space-y-2">
                        <label class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Email Body Template</label>
                        <textarea
                            id="emailBody"
                            placeholder="Hi {{customer_name}}, thank you for your order! Your total is {{order_total}}."
                            rows="12"
                            class="w-full p-3 text-sm border border-gray-300 dark:border-gray-600 rounded-md resize-none text-gray-800 dark:bg-gray-800"><?= htmlspecialchars($template['body']) ?></textarea>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2 justify-end flex-col sm:flex-row">
                        <button
                            id="clearBtn"
                            class="btn btn-cstm-muted flex justify-center gap-2 items-center w-full sm:w-fit"
                            data-modal-target="clearFormConfirmModal" type="button">
                            <i class="fas fa-undo-alt text-sm"></i> Clear Form
                        </button>
                        <button
                            id="sendTestBtn"
                            class="btn btn-cstm-primary light flex justify-center gap-2 items-center w-full sm:w-fit"
                            data-modal-target="sendTestEmailModal" type="button">
                            <i class="fas fa-paper-plane text-sm"></i> Send Test Email
                        </button>
                        <button
                            id="saveTemplateBtn"
                            class="btn btn-cstm-primary flex justify-center gap-2 items-center w-full sm:w-fit"
                            type="button">
                            <i class="far fa-save dark:text-gray-200 text-sm"></i> Save Template
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-span-12 lg:col-span-4">

            <div class="card rounded-lg border border-gray-200 shadow-sm p-6 dark:border-gray-600">
                <div class="mb-6">
                    <h3 class="text-base text-neutral-600 dark:text-neutral-200 flex items-center gap-2">
                        <i class="fas fa-key text-gray-600 dark:text-gray-200 text-base"></i> Available Tokens
                    </h3>
                </div>
                <div id="dynamicFieldsContainer" class="space-y-4"></div>
                <div class="mt-6">
                    <button
                        id="addNewTokenBtn"
                        class="w-full btn btn-cstm-primary flex items-center justify-center gap-2"
                        data-modal-target="addCustomTokenModal" type="button">
                        <i class="fas fa-plus text-xs"></i> Add Custom Token (Advanced)
                    </button>
                </div>
            </div>
        </div>

        <div class="col-span-12">
            <div class="card rounded-lg border border-gray-200 dark:border-gray-600 shadow-sm p-6 dark:border-gray-600">
                <div class="mb-6">
                    <h3 class="text-base text-neutral-600 dark:text-neutral-200 flex items-center gap-2">
                        <i class="far fa-eye text-gray-600 dark:text-gray-200 text-base"></i> Live Preview
                    </h3>
                </div>

                <div class="border border-gray-200 dark:border-gray-600 rounded-md overflow-hidden">
                    <div class="bg-gray-100 dark:bg-gray-800 px-4 py-3 border-b border-gray-200 dark:border-gray-600">
                        <div class="space-y-1 text-sm text-gray-700">
                            <div><span class="font-medium text-gray-500">Subject:</span> <span id="previewSubject" class="text-gray-800 font-semibold">Your email subject will appear here</span></div>
                        </div>
                    </div>

                    <div class="p-6 min-h-80">
                        <div id="previewBody" class="text-gray-600 dark:text-gray-200 text-base leading-relaxed overflow-x-auto">
                            Your email template preview will appear here as you type...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Send Test Email Modal -->
<div id="sendTestEmailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg w-full max-w-2xl p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">
                Send Test Email
            </h3>
            <button type="button" id="closeSendTestModal" class="text-gray-500 hover:text-gray-700">
                ✕
            </button>
        </div>

        <div class="space-y-4">
            <div>
                <label for="testEmailInput" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                    Test Email Address
                </label>
                <input
                    type="email"
                    id="testEmailInput"
                    class="form-control rounded-lg w-full"
                    placeholder="you@example.com">
            </div>

            <p class="text-xs text-gray-500">
                The test email will use the current Subject and Body shown in the editor (even if not yet saved).
            </p>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <button type="button" id="cancelSendTestBtn" class="btn btn-cstm-muted">
                Cancel
            </button>
            <button type="button" id="confirmSendTestBtn" class="btn btn-cstm-primary">
                Send Test
            </button>
        </div>
    </div>
</div>
<!-- START - save template modal popup -->
<div id="templateSavedModal" tabindex="-1" aria-hidden="true" class="hidden modal-container fixed flex items-center justify-center inset-0 z-50 bg-cstm-black-60">
    <div class="relative p-4 w-full max-w-2xl max-h-full">
        <div class="relative bg-white rounded-lg shadow dark:bg-dark-2 text-center">

            <div class="p-6">
                <iconify-icon icon="mdi:file-check" class="menu-icon text-4xl text-green-500 mb-2"></iconify-icon>

                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                    Template Saved Successfully!
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Your template has been updated.
                </p>
            </div>

            <div class="flex justify-center p-4 border-t dark:border-gray-600">
                <button type="button"
                    class="btn btn-cstm-primary"
                    id="templateSavedOkBtn">
                    Okay
                </button>
            </div>

        </div>
    </div>
</div>
<!-- END - save template modal popup -->

<?php include './partials/layouts/layoutBottom.php' ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const categorySelect = document.getElementById('category');
        const templateCategoryId = <?= json_encode($template['category_id']) ?>;

        // Pre-select category
        categorySelect.value = templateCategoryId;
        // === Send Test Email Logic ===
        const sendTestBtn = document.getElementById('sendTestBtn');
        const sendTestModal = document.getElementById('sendTestEmailModal');
        const closeTestModalBtn = document.getElementById('closeSendTestModal');
        const cancelSendTestBtn = document.getElementById('cancelSendTestBtn');
        const confirmSendTestBtn = document.getElementById('confirmSendTestBtn');
        const testEmailInput = document.getElementById('testEmailInput');

        function openSendTestModal() {
            sendTestModal.classList.remove('hidden');
            sendTestModal.classList.add('flex');
        }

        function closeSendTestModal() {
            sendTestModal.classList.add('hidden');
            sendTestModal.classList.remove('flex');
        }

        sendTestBtn.addEventListener('click', () => {
            openSendTestModal();
        });

        closeTestModalBtn.addEventListener('click', closeSendTestModal);
        cancelSendTestBtn.addEventListener('click', closeSendTestModal);

        confirmSendTestBtn.addEventListener('click', () => {
            const testEmail = testEmailInput.value.trim();
            if (!testEmail) {
                alert('Please enter a test email address.');
                return;
            }

            const templateName = document.getElementById('templateName').value;
            const subject = document.getElementById('emailSubject').value;
            const body = document.getElementById('emailBody').value;

            // Optionally: simple placeholder values for test
            const placeholders = {
                '{{customer_name}}': 'Test User',
                '{{user_name}}': 'Test User',
                '{{category_name}}': categorySelect.options[categorySelect.selectedIndex]?.text || '',
                '{{unsubscribe_link}}': 'https://example.com/unsubscribe'
            };

            fetch('/wix/googlesearchconsole/admin/core/email/send-test.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        to_email: testEmail,
                        template_name: templateName,
                        subject: subject,
                        body: body,
                        placeholders: placeholders
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Test email sent successfully!');
                        closeSendTestModal();
                    } else {
                        alert('Failed to send test email: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(err => {
                    console.error('Error sending test email:', err);
                    alert('An error occurred while sending the test email.');
                });
        });

        // Update live preview when category or input changes
        function updatePreview() {
            const category = categorySelect.options[categorySelect.selectedIndex].text;
            const subject = document.getElementById('emailSubject').value;
            const body = document.getElementById('emailBody').value;

            // Replace {{category_name}} with the category in both subject and body
            const previewSubject = subject.replace('{{category_name}}', category);
            const previewBody = body.replace('{{category_name}}', category);

            // Replace the {{user_name}} with actual value in body and subject for preview
            const finalSubject = previewSubject.replace('{{user_name}}', 'John Doe'); // Replace token with real value
            const finalBody = previewBody.replace('{{user_name}}', 'John Doe'); // Replace token with real value

            // Update preview with raw HTML content using innerHTML
            document.getElementById('previewSubject').innerHTML = finalSubject;
            document.getElementById('previewBody').innerHTML = finalBody; // Use innerHTML to render HTML properly
        }


        // Bind the category change to update the preview
        categorySelect.addEventListener('change', updatePreview);

        // Bind input changes to update the live preview
        ['emailSubject', 'emailBody'].forEach(id => {
            document.getElementById(id).addEventListener('input', updatePreview);
        });

        // Save Template Button -> Save the template
        document.getElementById('saveTemplateBtn').addEventListener('click', () => {
            const templateName = document.getElementById('templateName').value;
            const category = categorySelect.value;
            const subject = document.getElementById('emailSubject').value;
            const body = document.getElementById('emailBody').value;

            const data = {
                id: <?= json_encode($templateId) ?>, // Pass the template ID for updating
                name: templateName,
                category_id: category,
                subject: subject,
                body: body
            };

            fetch('/wix/googlesearchconsole/admin/core/email/save-template.php', {
                    method: 'POST',
                    body: JSON.stringify(data),
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const popup = document.getElementById("templateSavedModal");

                        // SHOW POPUP
                        popup.classList.remove("hidden");
                        popup.classList.add("flex");
                        document.body.style.overflow = "hidden";

                        // OK BUTTON ACTION
                        document.getElementById("templateSavedOkBtn").onclick = function() {
                            popup.classList.add("hidden");
                            popup.classList.remove("flex");
                            document.body.style.overflow = "";
                            window.location.href = 'email-templates.php';
                        };
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error saving template:', error);
                    alert('An error occurred while saving the template.');
                });
        });

        // Initial preview update
        updatePreview();
    });
</script>