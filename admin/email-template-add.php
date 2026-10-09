<?php
// Include core files for session and authentication logic
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
                            <input type="text" class="form-control rounded-lg" id="templateName" placeholder="e.g., Order Confirmation">
                        </div>

                        <div class="space-y-2">
                            <label class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Category</label>
                            <select class="form-control rounded-lg mt-0" id="category">
                                <option value="">Select category...</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">
                            Email Subject
                            <span class="text-xs text-gray-500">(Use tokens like <span class="text-cstm-primary drk-text-cstm-primary">\{\{name\}\}</span>)</span>
                        </label>
                        <input type="text" class="form-control rounded-lg" id="emailSubject" placeholder="Your {{company_name}} order is confirmed!">
                    </div>

                    <div class="space-y-2">
                        <label class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">Email Body Template</label>
                        <textarea
                            id="emailBody"
                            placeholder="Hi {{customer_name}}, thank you for your order! Your total is {{order_total}}."
                            rows="12"
                            class="w-full p-3 text-sm border border-gray-300 dark:border-gray-600 rounded-md resize-none text-gray-800 dark:bg-gray-800"></textarea>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2 justify-end flex-col sm:flex-row">
                        <button
                            id="clearBtn"
                            class="btn btn-cstm-muted flex justify-center gap-2 items-center w-full sm:w-fit"
                            type="button">
                            <i class="fas fa-undo-alt text-sm"></i> Clear Form
                        </button>
                        <button
                            id="sendTestBtn"
                            class="btn btn-cstm-primary light flex justify-center gap-2 items-center w-full sm:w-fit"
                            type="button">
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
                        type="button">
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
                        <div id="previewBody" class="text-gray-600 dark:text-gray-200 text-base leading-relaxed">
                            Your email template preview will appear here as you type...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include './partials/layouts/layoutBottom.php' ?>

<script>
    // Simple CSS for previewing the replaced tokens
    const DYNAMIC_PLACEHOLDER_STYLE = `
        <style>
            .dynamic-placeholder {
                font-weight: 500;
            }

            /* Ensure modals display correctly when shown */
            .modal-container.hidden {
                display: none !important;
            }
            .modal-container:not(.hidden) {
                display: flex !important;
            }

            .modal-container {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                justify-content: center;
                align-items: center;
                z-index: 50;
            }
        </style>
    `;

    // A minimal class to manage the modals' visibility
    class ModalHandler {
    show(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden'; // Prevent background scroll
        }
    }

    hide(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            // Only re-enable scrolling if no other modals are open
            if (document.querySelectorAll('.modal-container:not(.hidden)').length === 0) {
                document.body.style.overflow = '';
            }
        }
    }

    alert(message, title = 'Alert', type = 'warning') {
        const modal = document.getElementById('alertModal');
        const icon = document.getElementById('alertIcon');
        const alertTitle = document.getElementById('alertTitle');
        const alertMessage = document.getElementById('alertMessage');

        // Check if the modal and its child elements are present
        if (modal && icon && alertTitle && alertMessage) {
            alertTitle.textContent = title;
            alertMessage.textContent = message;

            // Update icon and color based on type
            icon.className = 'fas text-4xl mb-4';
            if (type === 'error') {
                icon.classList.add('fa-times-circle', 'text-danger-600');
            } else if (type === 'success') {
                icon.classList.add('fa-check-circle', 'text-success-500');
            } else { // default warning
                icon.classList.add('fa-exclamation-circle', 'text-warning-500');
            }

            this.show('alertModal');
        } else {
            console.error('Alert modal or its elements are missing in the DOM.');
        }
    }
}


    const modalHandler = new ModalHandler();

    class EmailTemplateBuilder {
        constructor() {
            this.tokens = [{
                    name: 'customer_name',
                    testValue: 'John Doe',
                    isRemovable: false
                },
                {
                    name: 'company_name',
                    testValue: 'Your Company Inc.',
                    isRemovable: false
                },
                {
                    name: 'order_total',
                    testValue: '$49.99',
                    isRemovable: true
                },
                {
                    name: 'link_to_product',
                    testValue: 'View Product Link',
                    isRemovable: true
                }
            ];
            this.fieldCounter = this.tokens.length;

            this.init();
        }

        init() {
            document.head.insertAdjacentHTML('beforeend', DYNAMIC_PLACEHOLDER_STYLE);

            this.bindEvents();
            this.renderTokens();
            this.updatePreview();
        }

        bindEvents() {
            // Bind input changes to update the live preview
            ['templateName', 'emailSubject', 'emailBody'].forEach(id => {
                document.getElementById(id).addEventListener('input', () => {
                    this.updatePreview();
                });
            });

            // Save Template Button -> Sends data to the server to save the template
            document.getElementById('saveTemplateBtn').addEventListener('click', () => {
                if (this.validateForm()) {
                    this.saveTemplate();
                    modalHandler.show('saveTemplateSuccessModal'); // Show success modal
                } else {
                    modalHandler.alert('Please fill in Template Name, Category, Subject, and Body.', 'Missing Information', 'error');
                }
            });
        }

        // Helper function for form validation
        validateForm() {
            const templateName = document.getElementById('templateName').value.trim();
            const category = document.getElementById('category').value.trim();
            const subject = document.getElementById('emailSubject').value.trim();
            const body = document.getElementById('emailBody').value.trim();
            return templateName && category && subject && body;
        }

        renderTokens() {
            const container = document.getElementById('dynamicFieldsContainer');
            container.innerHTML = this.tokens.map(token => this.getTokenHtml(token)).join('');

            container.querySelectorAll('.insert-token-btn').forEach(button => {
                button.addEventListener('click', (e) => this.insertToken(e.currentTarget.dataset.tokenName));
            });

            container.querySelectorAll('.token-test-value').forEach(input => {
                input.addEventListener('input', () => this.updatePreview());
            });
        }

        getTokenHtml(token) {
            return `
                <div class="flex flex-col sm:flex-row items-end gap-2">
                    <div class="flex-1 space-y-2 w-full">
                        <label class="block">
                            <code class="inline-block font-semibold text-neutral-600 dark:text-neutral-200 text-sm mb-2">
                                {{\{${token.name}\}\}}
                            </code>
                        </label>
                        <input 
                            type="text" 
                            data-token-name="${token.name}"
                            value="${token.testValue}" 
                            placeholder="Test Value for Preview" 
                            class="form-control rounded-lg token-test-value" 
                        >
                    </div>
                    <div class="flex gap-2">
                        <button 
                            type="button" 
                            data-token-name="${token.name}"
                            class="insert-token-btn btn btn-cstm-primary light w-10 justify-center flex"
                        >
                            <i class="fas fa-arrow-alt-circle-down mr-1 text-lg"></i>
                        </button>
                        ${token.isRemovable ?  
                            `<button 
                                type="button" 
                                onclick="templateBuilder.showRemoveTokenConfirm('${token.name}')"
                                class="btn btn-cstm-danger light w-10 justify-center flex"
                                title="Remove Custom Token"
                            >
                                <i class="fas fa-trash text-lg"></i>
                            </button>` 
                            : ''}
                    </div>
                </div>
            `;
        }

        insertToken(name) {
            const tokenString = `{{${name}}}`;
            const subjectInput = document.getElementById('emailSubject');
            const bodyTextarea = document.getElementById('emailBody');

            const target = document.activeElement.id === 'emailSubject' ? subjectInput : bodyTextarea;

            const start = target.selectionStart;
            const end = target.selectionEnd;
            const value = target.value;

            target.value = value.substring(0, start) + tokenString + value.substring(end);

            target.focus();
            target.selectionEnd = start + tokenString.length;

            this.updatePreview();
        }

        // --- Preview Logic ---
        getTestValues() {
            const values = {};
            document.querySelectorAll('.token-test-value').forEach(input => {
                const name = input.dataset.tokenName;
                const value = input.value.trim();
                values[name] = value;
            });
            return values;
        }

        updatePreview() {
            const subject = document.getElementById('emailSubject').value;
            let body = document.getElementById('emailBody').value;
            const testValues = this.getTestValues();

            let previewBody = body || 'Your email template preview will appear here as you type...';
            previewBody = previewBody.replace(/\n/g, '<br>');

            let previewSubject = subject || 'Your email subject will appear here';

            Object.keys(testValues).forEach(name => {
                const regex = new RegExp(`\\{\\{${name}\\}\\}`, 'g');
                const testValue = testValues[name];

                const styledReplacement = `<span class="dynamic-placeholder">${testValue}</span>`;

                previewSubject = previewSubject.replace(regex, styledReplacement);
                previewBody = previewBody.replace(regex, styledReplacement);
            });

            document.getElementById('previewSubject').innerHTML = previewSubject;
            document.getElementById('previewBody').innerHTML = previewBody;
        }

        // --- Action Handlers ---
saveTemplate() {
    console.log('Save Template function triggered');
    const templateName = document.getElementById('templateName').value.trim();
    const category = document.getElementById('category').value.trim();
    const subject = document.getElementById('emailSubject').value.trim();
    const body = document.getElementById('emailBody').value.trim();

    // Prepare the data for the API call
    const data = {
        name: templateName,
        category_id: category,  // The ID of the category selected
        subject: subject,
        body: body
    };

    console.log('Data to send:', data);  // Log the data being sent to the server

    // Make an AJAX call to save the template
    fetch('/wix/googlesearchconsole/admin/core/email/save-template.php', {  // Adjust the endpoint as necessary
        method: 'POST',
        body: JSON.stringify(data),
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        console.log('Response from server:', data); // Log the response da  ta
        if (data.success) {
            modalHandler.alert('Template saved successfully!', 'Success', 'success');
            // Redirect to the email templates page after saving
            window.location.href = '/wix/googlesearchconsole/admin/email-templates.php';  // Redirect to the templates page
        } else {
            modalHandler.alert(data.message || 'Failed to save template.', 'Error', 'error');
        }
    })
    .catch(error => {
        modalHandler.alert('An error occurred while saving the template. Please try again.', 'Error', 'error');
        console.error('Error saving template:', error);
    });
}

    }

    // Initialize the template builder instance
    const templateBuilder = new EmailTemplateBuilder();
</script>
