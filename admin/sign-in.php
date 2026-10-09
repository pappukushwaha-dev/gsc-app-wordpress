<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1);

// 1) Make sure $pdo exists before sessions use it
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/core/SessionManager.php';
require_once __DIR__ . '/core/AuthController.php';
require_once __DIR__ . '/core/HeaderData.php'; // Include HeaderData.php to get the logo path

// 2) Start the database-backed session (now $pdo is defined)
SessionManager::startDatabaseSession();

// Fetch the logo path dynamically from admin settings
$adminData = get_app_settings($pdo);
$logoPath = $adminData['logo_path'] ?? 'assets/images/analyticslogo1.png'; // Fallback if not found

// 3) If already authenticated, honor both HTML and JSON callers
if (AuthController::isAuthenticated()) {
    // If it's an AJAX/JSON request, respond with JSON
    $isJson = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false;
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'redirect' => '/index.php']);
        exit;
    }
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

$error = '';

// 4) Handle POST for both JSON and form-encoded bodies
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isJson = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false;

    if ($isJson) {
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $email = trim($payload['email'] ?? '');
        $password = (string)($payload['password'] ?? '');
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
    }

    $loginResult = AuthController::login($email, $password);

    if ($isJson) {
        header('Content-Type: application/json');
        if ($loginResult['success']) {
            echo json_encode(['success' => true, 'message' => 'Login successful.', 'redirect' => 'index.php']);
        } else {
            // Always a 200 with a structured error is also fine; if you prefer non-2xx, set 400 here.
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $loginResult['message']]);
        }
        exit;
    }

    // Non-AJAX form submission path
    if ($loginResult['success']) {
        header('Location: ' . APP_BASE . '/index.php');
        exit;
    } else {
        $error = $loginResult['message'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<?php include './partials/head.php' ?>

<body class="dark:bg-neutral-800 bg-neutral-100 dark:text-white">
    <section class="bg-white dark:bg-dark-2 flex flex-wrap min-h-[100vh]">
        <div class="lg:w-1/2 lg:block hidden">
            <div class="flex items-center flex-col h-full justify-center">
                <img src="assets/images/sign-in.jpg" alt="">
            </div>
        </div>
        <div class="lg:w-1/2 py-8 px-6 flex flex-col justify-center">
            <div class="lg:max-w-[464px] mx-auto w-full">
                <div>
                    <a href="index.php" class="mb-2.5 max-w-[290px]">
                        <img src="<?= htmlspecialchars($logoPath) ?>" alt="Site Logo">
                    </a>
                    <h4 class="mb-3">Sign In to your Account</h4>
                    <p class="mb-8 text-secondary-light text-lg">Welcome back! Please enter your details</p>
                </div>

                <form id="login-form">
                    <div class="icon-field mb-4 relative">
                        <span class="absolute start-4 top-1/2 -translate-y-1/2 pointer-events-none flex text-xl dark:text-neutral-600">
                            <iconify-icon icon="mage:email"></iconify-icon>
                        </span>
                        <input type="email" name="email" class="form-control h-[56px] ps-11 border-neutral-300 bg-neutral-50 dark:bg-dark-2 rounded-xl" id="email" placeholder="Email" required>
                    </div>
                    
                    <div class="icon-field mb-4 relative">
                        <span class="absolute start-4 top-1/2 -translate-y-1/2 pointer-events-none flex text-xl dark:text-neutral-600">
                            <iconify-icon icon="mingcute:lock-fill"></iconify-icon>
                        </span>

                        <input
                            type="password"
                            name="password"
                            class="form-control h-[56px] ps-11 pr-11 border-neutral-300 bg-neutral-50 dark:bg-dark-2 rounded-xl"
                            id="password"
                            placeholder="Password"
                            required>

                        <button type="button" id="toggle-password" class="absolute end-4 top-1/2 -translate-y-1/2 text-gray-500">
                            <iconify-icon icon="mdi:eye-off" id="toggle-password-icon"></iconify-icon>
                        </button>
                    </div>

                    <div id="error-message" class="hidden text-red-500 font-medium mb-4"></div>
                    <button type="submit" class="btn btn-cstm-primary hover:btn-cstm-primary border border-cstm-primary justify-center text-sm btn-sm px-3 py-4 w-full rounded-xl mt-4">Sign In</button>
                </form>

            </div>
        </div>
    </section>

    <?php $script = '<script>
document.addEventListener("DOMContentLoaded", function() {
    const loginForm = document.getElementById("login-form");
    const errorMessageElement = document.getElementById("error-message");

    // Function to send the POST request
    async function postJSON(body) {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body)
        });

        let data = {};
        try {
            data = await res.json();  // Parse the JSON response from the server
        } catch (e) {
            throw new Error("Error parsing response from server.");
        }

        if (!res.ok || !data.success) {
            throw new Error(data.message || "Request failed");
        }

        return data;
    }

    // Handle form submission
    loginForm.addEventListener("submit", async function(e) {
        e.preventDefault(); // Prevent the default form submission

        // Hide previous errors
        errorMessageElement.classList.add("hidden");

        const email = document.getElementById("email").value.trim();
        const password = document.getElementById("password").value;

        try {
            const data = await postJSON({ action: "login", email, password });
            const redirect = data.redirect || "index.php";  // Get the redirect URL from response
            window.location.href = redirect;  // Redirect to the desired page
        } catch (e) {
            // Display error message on the form
            errorMessageElement.textContent = e.message;
            errorMessageElement.classList.remove("hidden");
        }
    });
});


// Password visibility toggle
const toggleBtn = document.getElementById("toggle-password");
const passwordInput = document.getElementById("password");
const toggleIcon = document.getElementById("toggle-password-icon");

if (toggleBtn) {
    toggleBtn.addEventListener("click", function () {
        const isPassword = passwordInput.getAttribute("type") === "password";
        passwordInput.setAttribute("type", isPassword ? "text" : "password");
        toggleIcon.setAttribute("icon", isPassword ? "mdi:eye" : "mdi:eye-off");
    });
}


</script>'; ?>

    <?php include './partials/script.php' ?>

</body>

</html>