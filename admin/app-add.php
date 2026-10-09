<?php
$title = 'Add App';
$subTitle = 'Add a new app to Our Other Apps';

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
require_once __DIR__ . '/core/OtherAppsManager.php';

SessionManager::startDatabaseSession();

if (!AuthController::isAuthenticated()) {
    header('Location: ' . APP_BASE . '/admin/sign-in.php');
    exit;
}

// Initialize Manager
OtherAppsManager::initialize($pdo);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $imagePath = null;

    if (!empty($_FILES['image_file']['name'])) {
        // Absolute path for server-side upload
        $uploadDir = __DIR__ . '/../assets/images/apps/';

        // Ensure the directory exists
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Sanitize file name
        $fileName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['image_file']['name']));
        $targetFile = $uploadDir . $fileName;

        // Allowed MIME types
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        if (in_array($_FILES['image_file']['type'], $allowedTypes)) {
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $targetFile)) {
                // Relative path to store in DB (for display)
                $imagePath = './assets/images/apps/' . $fileName;
            } else {
                $errors[] = 'Failed to move uploaded file.';
            }
        } else {
            $errors[] = 'Invalid file type. Allowed: JPG, PNG, WEBP, GIF, SVG.';
        }
    }

    $data = [
        'title'       => trim($_POST['title'] ?? ''),
        'subtitle'    => trim($_POST['subtitle'] ?? ''),
        'tag_line'    => trim($_POST['tag_line'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'image_url'   => $imagePath,
        'button_text' => trim($_POST['button_text'] ?? 'Install Now'),
        'app_price' => trim($_POST['app_price'] ?? '0'),
        'button_link' => trim($_POST['button_link'] ?? ''),
        'status'      => $_POST['status'] ?? 'active',
        'sort_order'  => (int)($_POST['sort_order'] ?? 0),
        'is_index'  => trim($_POST['is_index'] ?? 'disable'),
    ];

    // Basic validation
    if ($data['title'] === '') {
        $errors[] = 'Title is required.';
    }
    if ($data['button_link'] === '') {
        $errors[] = 'APP Url is required.';
    }

    if (empty($errors)) {
        $success = OtherAppsManager::createApp($data);
        if ($success) {
            header("Location: our-apps.php?msg=added");
            exit;
        } else {
            $errors[] = 'Failed to add app. Please try again.';
        }
    }
}

include './partials/layouts/layoutTop.php';
?>

<div class="card rounded-xl border-0 overflow-hidden">
    <div class="card-header border-b border-neutral-200 dark:border-neutral-600 bg-white dark:bg-neutral-700 py-4 px-6">
        <h6 class="text-lg font-semibold text-neutral-800 dark:text-neutral-200">Add New App</h6>
    </div>
    <div class="card-body p-6">
        <?php if (!empty($errors)): ?>
            <div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg">
                <?php foreach ($errors as $err): ?>
                    <p><?php echo htmlspecialchars($err); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="app-add.php" enctype="multipart/form-data">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block mb-2 text-sm font-medium">Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" class="form-control rounded-lg" required>
                </div>
                <div>
                    <label class="block mb-2 text-sm font-medium">Subtitle</label>
                    <input type="text" name="subtitle" class="form-control rounded-lg">
                </div>
                <div>
                    <label class="block mb-2 text-sm font-medium">Tag Line</label>
                    <input type="text" name="tag_line" class="form-control rounded-lg">
                </div>
                <div class="md:col-span-2">
                    <label class="block mb-2 text-sm font-medium">Description</label>
                    <textarea name="description" rows="5" class="form-control rounded-lg"></textarea>
                </div>
                <div>
                    <label class="block mb-2 text-sm font-medium">App Image</label>
                    <input type="file" name="image_file" id="image_file" accept="image/*" class="form-control rounded-lg">
                    <div class="mt-3">
                        <img id="image_preview" src="" alt="Preview" style="max-width: 200px; display:none; border-radius: 8px;">
                    </div>
                </div>
                <div>
                    <label class="block mb-2 text-sm font-medium">APP Url <span class="text-red-500">*</span></label>
                    <input type="text" name="button_link" class="form-control rounded-lg" required>
                </div>
                <div>
                    <label class="block mb-2 text-sm font-medium">Button Text</label>
                    <input type="text" name="button_text" class="form-control rounded-lg" value="Install Now">
                </div>
                <div>
                    <label class="block mb-2 text-sm font-medium">App Price (For Dashboard Apps)</label>
                    <input type="text" name="app_price" class="form-control rounded-lg" value="0">
                </div>
                <div>
                    <label class="block mb-2 text-sm font-medium">Status</label>
                    <select name="status" class="form-select rounded-lg">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div>
                    <label class="block mb-2 text-sm font-medium">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control rounded-lg" value="0">
                </div>

                <div>
                    <label class="block mb-2 text-sm font-medium">
                        App on Dashboard
                    </label>
                    <div class="flex items-center gap-4">
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="is_index" value="enable" class="sr-only peer" id="isIndexToggle">

                            <div id="indexToggleBg"
                                class="w-11 h-6 bg-danger-500 rounded-full
                                        dark:bg-danger-700
                                        peer-checked:after:translate-x-full
                                        peer-checked:after:border-white
                                        after:content-['']
                                        after:absolute after:top-[2px] after:left-[2px]
                                        after:bg-white after:border-gray-300 after:border
                                        after:rounded-full after:h-5 after:w-5 after:transition-all
                                        dark:border-gray-600">
                            </div>
                        </label>

                        <span id="indexLabel" class="text-sm font-medium text-red-500">
                            Disabled
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex items-center justify-start gap-6">
                <button type="submit" class="btn btn-cstm-primary">Add App</button>
                <a href="our-apps.php" class="btn bg-neutral-300 dark:bg-neutral-600">Cancel</a>
            </div>
        </form>
    </div>
</div>
<script>
    document.getElementById('image_file').addEventListener('change', function(event) {
        const file = event.target.files[0];
        const preview = document.getElementById('image_preview');
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            preview.src = '';
            preview.style.display = 'none';
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('isIndexToggle');
        const label = document.getElementById('indexLabel');
        const bg = document.getElementById('indexToggleBg');

        function syncToggle() {
            if (toggle.checked) {
                label.textContent = 'Enabled';

                bg.classList.remove('bg-danger-500', 'dark:bg-danger-700');
                bg.classList.add('bg-success-500', 'dark:bg-success-700');
            } else {
                label.textContent = 'Disabled';

                bg.classList.remove('bg-success-500', 'dark:bg-success-700');
                bg.classList.add('bg-danger-500', 'dark:bg-danger-700');
            }
        }

        toggle.addEventListener('change', syncToggle);
        syncToggle(); // initial state
    });
</script>


<?php include './partials/layouts/layoutBottom.php'; ?>