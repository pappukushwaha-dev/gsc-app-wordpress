<?php
// ----- BOOTSTRAP -----
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 1) If already logged in (instance in session), go to index.php
if (!empty($_SESSION['instance_id'])) {
    header('Location: index.php');
    exit;
}


// (Optional) accept instanceid via URL/JWT and log in instantly
function b64url_decode($s){ return base64_decode(strtr($s,'-_','+/')); }
if (!empty($_GET['instanceid'])) {
    $_SESSION['instance_id'] = $_GET['instanceid'];
    header('Location: index.php'); exit;
} elseif (!empty($_GET['instance'])) {
    $p = explode('.', $_GET['instance']);
    if (count($p) >= 2) {
        $payload = json_decode(b64url_decode($p[1]), true);
        if (!empty($payload['instanceId'])) {
            $_SESSION['instance_id'] = $payload['instanceId'];
            header('Location: index.php'); exit;
        }
    }
}

// 2) Dependencies (DB + mailer)
require __DIR__ . '/includes/config.php'; // defines $pdo
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/vendor/autoload.php'; 

// Fetch the logo path dynamically

$logoPath = $adminData['logo_path'] ?? 'assets/images/analyticslogo1.png'; // Fallback if not found

// Optional (helps debugging): throw PDO exceptions
if (isset($pdo) && $pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// 3) Small JSON helpers
function json_ok($arr = []) { header('Content-Type: application/json'); echo json_encode(['ok'=>true] + $arr); exit; }
function json_err($msg, $code = 400) { header('Content-Type: application/json'); http_response_code($code); echo json_encode(['ok'=>false,'message'=>$msg]); exit; }

if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', 'base64:mbZ7aAT6pVa5f4Ratw22WYBP99OAAVXU8Y2l+ZwUsCM=');
}

function decrypt($data, $key)
{
    if (strpos($key, 'base64:') === 0) {
        $key = base64_decode(substr($key, 7));
    }

    $data = base64_decode($data);

    if ($data === false) {
        return false;
    }

    $ivLength = openssl_cipher_iv_length('aes-256-cbc');

    if (strlen($data) <= $ivLength) {
        return false;
    }

    $iv = substr($data, 0, $ivLength);
    $cipher = substr($data, $ivLength);

    return openssl_decrypt(
        $cipher,
        'aes-256-cbc',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );
}

function decryptAdminValue(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }

    $decrypted = decrypt($value, ENCRYPTION_KEY);

    if ($decrypted === false) {
        return null;
    }

    return $decrypted;
}

function sendOtpEmail(PDO $pdo, string $toEmail, string $otp): bool
{
    // Load SMTP settings
    $stmt = $pdo->query("SELECT key_name, value FROM admin_settings");
    $settings = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key_name']] = $row['value'];
    }

    $smtpHost     = trim($settings['smtp_host'] ?? '');
    $smtpUsername = trim(decryptAdminValue($settings['smtp_username'] ?? '') ?? '');
    $smtpPassword = trim(decryptAdminValue($settings['smtp_password'] ?? '') ?? '');
    $smtpPort     = (int)($settings['smtp_port'] ?? 587);
    $smtpTls      = strtolower(trim($settings['smtp_tls'] ?? 'tls'));

    $senderEmail  = trim($settings['sender_email'] ?? '');
    $senderName   = trim($settings['sender_name'] ?? 'Google Search Console');

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUsername;
        $mail->Password   = $smtpPassword;
        $mail->Port       = $smtpPort;
        $mail->CharSet    = 'UTF-8';

        $mail->SMTPSecure = ($smtpTls === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom($senderEmail, $senderName);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Your OTP for Login';

        $mail->Body = "
            <h2>Your OTP</h2>
            <h1>{$otp}</h1>
            <p>This OTP is valid for 5 minutes.</p>
        ";

        $mail->AltBody = "Your OTP is: {$otp}. This OTP is valid for 5 minutes.";

        return $mail->send();

    } catch (Exception $e) {
        error_log('OTP Mail Error: ' . $mail->ErrorInfo);
        error_log($e->getMessage());
        return false;
    }
}

// 5) Handle AJAX on this same page (always return JSON and exit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $action = is_array($data) ? ($data['action'] ?? '') : '';

    try {
        if ($action === 'send_otp') {
            $email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            if (!$email) json_err('Please provide a valid email.');

            // Lookup instance_id from WpSite by email
            $stmt = $pdo->prepare("SELECT instance_id FROM WpSite WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                // Generic message to avoid enumeration
                json_ok(['message' => 'If an account exists for that email, an OTP has been sent.']);
            }

            $instance_id = $row['instance_id'];
            $_SESSION['email'] = $email; // keep email only (do NOT set instance yet)

            // Make OTP and store (same table/columns you already use)
            $otp = random_int(100000, 999999);
            $expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            $sql = "INSERT INTO otp_verifications (email, otp, created_at, expires_at, is_verified, instance_id)
                    VALUES (?, ?, NOW(), ?, 0, ?)
                    ON DUPLICATE KEY UPDATE otp=VALUES(otp), expires_at=VALUES(expires_at), is_verified=0";
            $ins = $pdo->prepare($sql);
            $ins->execute([$email, $otp, $expires_at, $instance_id]);

            //  sendOtpEmail($email, $otp);
            if (!sendOtpEmail($pdo, $email, $otp)) {
                json_err("Mail send failed", 500);
            }
            json_ok(['message' => 'If an account exists for that email, an OTP has been sent.']);

        } elseif ($action === 'verify_otp') {
            $email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $otp = trim((string)($data['otp'] ?? ''));
            if (!$email) json_err('Please provide a valid email.');
            if (!preg_match('/^\d{6}$/', $otp)) json_err('Invalid OTP format.');

            // Get instance_id for this email (we did not set it in session yet)
            $stmt = $pdo->prepare("SELECT instance_id FROM WpSite WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('Invalid or expired OTP.', 401);

            $instance_id = $row['instance_id'];

            // Validate OTP against otp_verifications
            $sql = "SELECT id FROM otp_verifications
                    WHERE email = ? AND otp = ? AND is_verified = 0 AND expires_at > NOW() AND instance_id = ?
                    LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$email, $otp, $instance_id]);
            $otpRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$otpRow) json_err('Invalid or expired OTP.', 401);

            // Mark as verified
            $upd = $pdo->prepare("UPDATE otp_verifications SET is_verified = 1 WHERE id = ?");
            $upd->execute([$otpRow['id']]);

            // Now we log the user in: set session instance and redirect
            unset($_SESSION['email']);
            $_SESSION['instanceid'] = $instance_id; // single source of truth
            $_SESSION['instance_id']  =  $instance_id;
            session_regenerate_id(true);

            json_ok(['redirect' => 'index.php']);

        } else {
            json_err('Bad request', 400);
        }
    }catch (Throwable $e) {
    error_log($e);
    // TEMP: expose the root cause so we can fix it fast
    json_err($e->getMessage(), 500);
}

    // ensure no HTML is sent to fetch()
    exit;
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
                       <img src="<?= htmlspecialchars($logoPath) ?>" alt="">
                    </a>
                    <h4 class="mb-3">Sign In to your Account</h4>
                    <p class="mb-8 text-secondary-light text-lg">Welcome back! Please enter your detail</p>
                </div>

                <form action="#">
                    <div id="email-step" class="block">
                        <div class="icon-field mb-4 relative">
                            <span class="absolute start-4 top-1/2 -translate-y-1/2 pointer-events-none flex text-xl">
                                <iconify-icon icon="mage:email"></iconify-icon>
                            </span>
                            <input type="email" class="form-control h-[56px] ps-11 border-neutral-300 bg-neutral-50 dark:bg-dark-2 rounded-xl" id="email" placeholder="Email" required>
                        </div>
                        <button type="button" id="send-otp" class="btn btn-cstm-primary hover:btn-cstm-primary border border-cstm-primary text-sm justify-center btn-sm px-3 py-4 w-full rounded-xl mt-5">Send OTP</button>
                    </div>

                    <div id="otp-step" class="hidden">
                        <div class="flex flex-col items-center">
                            <p class="text-secondary-light mb-4 text-center">An OTP has been sent to your email. Please enter it below to sign in.</p>
                            <div class="otp-fields flex space-x-2">
                                <input type="text" class="otp-input w-12 h-12 text-center text-xl border rounded-lg" maxlength="1" id="otp1">
                                <input type="text" class="otp-input w-12 h-12 text-center text-xl border rounded-lg" maxlength="1" id="otp2">
                                <input type="text" class="otp-input w-12 h-12 text-center text-xl border rounded-lg" maxlength="1" id="otp3">
                                <input type="text" class="otp-input w-12 h-12 text-center text-xl border rounded-lg" maxlength="1" id="otp4">
                                <input type="text" class="otp-input w-12 h-12 text-center text-xl border rounded-lg" maxlength="1" id="otp5">
                                <input type="text" class="otp-input w-12 h-12 text-center text-xl border rounded-lg" maxlength="1" id="otp6">
                            </div>
                            <button type="button" id="verify-otp" class="btn btn-cstm-primary hover:btn-cstm-primary border border-cstm-primary text-sm justify-center btn-sm px-3 py-4 w-full rounded-xl mt-8">Verify OTP</button>
                            <button type="button" id="resend-otp" class="text-cstm-primary font-medium hover:underline mt-4">Resend OTP</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

<?php $script = '<script>
document.addEventListener("DOMContentLoaded", function() {
    const emailStep = document.getElementById("email-step");
    const otpStep = document.getElementById("otp-step");
    const sendOtpBtn = document.getElementById("send-otp");
    const verifyOtpBtn = document.getElementById("verify-otp");
    const resendBtn = document.getElementById("resend-otp");
    const otpInputs = document.querySelectorAll(".otp-input");
    const emailInput = document.getElementById("email");

    function getOtp() {
        return Array.from(otpInputs).map(i => i.value.replace(/\\D/g, "")).join("");
    }

    async function postJSON(body) {
        const res = await fetch(window.location.href, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body)
        });
        let data = {};
        try { data = await res.json(); } catch(e) {}
        if (!res.ok || data.ok === false) {
            throw new Error(data.message || "Request failed");
        }
        return data;
    }

    sendOtpBtn.addEventListener("click", async function() {
        if (!emailInput.checkValidity()) return emailInput.reportValidity();
        try {
            await postJSON({ action: "send_otp", email: emailInput.value.trim() });
            emailStep.classList.add("hidden");
            otpStep.classList.remove("hidden");
            otpInputs[0].focus();
        } catch (e) {
            alert(e.message);
        }
    });

    verifyOtpBtn.addEventListener("click", async function() {
        const otp = getOtp();
        if (otp.length !== 6) return alert("Please enter the full 6-digit OTP.");
        try {
            const data = await postJSON({ action: "verify_otp", email: emailInput.value.trim(), otp });
            const redirect = data.redirect || "index.php";
            window.location.href = redirect;
        } catch (e) {
            alert(e.message);
        }
    });

    if (resendBtn) {
        resendBtn.addEventListener("click", async function() {
            try {
                await postJSON({ action: "send_otp", email: emailInput.value.trim() });
                alert("If an account exists, a new OTP has been sent.");
            } catch (e) {
                alert(e.message);
            }
        });
    }

    otpInputs.forEach((input, index) => {
        input.setAttribute("inputmode","numeric");
        input.setAttribute("pattern","\\\\d*");

        input.addEventListener("input", function() {
            this.value = this.value.replace(/\\D/g, "").slice(0,1);
            if (this.value.length === 1 && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }
        });

        input.addEventListener("keydown", function(e) {
            if (e.key === "Backspace" && this.value === "" && index > 0) {
                otpInputs[index - 1].focus();
            }
            if (e.key === "ArrowRight" && index < otpInputs.length - 1) {
                otpInputs[index + 1].focus();
            }
            if (e.key === "ArrowLeft" && index > 0) {
                otpInputs[index - 1].focus();
            }
        });

        input.addEventListener("paste", function(e) {
            const paste = (e.clipboardData || window.clipboardData).getData("text").replace(/\\D/g, "");
            if (paste.length === 6) {
                for (let i = 0; i < 6; i++) otpInputs[i].value = paste[i] || "";
                otpInputs[5].focus();
            }
            e.preventDefault();
        });
    });
});
</script>'; ?>

<?php include './partials/script.php' ?>

</body>
</html>