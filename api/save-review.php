<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';

/*
================================================
| GET JSON DATA
================================================
*/

$data = json_decode(
    file_get_contents('php://input'),
    true
);

/*
================================================
| DEFAULT RESPONSE
================================================
*/

$response = [
    'success' => false,
    'message' => 'Something went wrong.'
];

/*
================================================
| VALIDATE INPUT
================================================
*/

if (
    !isset($data['instance_id']) ||
    !isset($data['rating']) ||
    !is_numeric($data['rating'])
) {

    echo json_encode([

        'success' => false,

        'message' =>
            'Missing or invalid data.'
    ]);

    exit;
}

/*
================================================
| CLEAN DATA
================================================
*/

$instanceId = trim(
    $data['instance_id']
);

$rating = (int) $data['rating'];

/*
================================================
| VALIDATE RATING RANGE
================================================
*/

if ($rating < 1 || $rating > 10) {

    echo json_encode([

        'success' => false,

        'message' =>
            'Rating must be between 1 and 10.'
    ]);

    exit;
}

/*
================================================
| COMMENT
================================================
*/

$comment = trim(
    $data['comment'] ?? ''
);

/*
| Auto comment for Wix review redirect
*/

if ($rating >= 8) {

    $comment = 'Ecwid review';
}

/*
================================================
| USER INFO
================================================
*/

$ipAddress =
    $_SERVER['REMOTE_ADDR'] ?? null;

$userAgent =
    $_SERVER['HTTP_USER_AGENT'] ?? null;

try {

    /*
    ============================================
    | FETCH OWNER EMAIL
    ============================================
    */

    $q = $pdo->prepare("
           SELECT email 
    FROM WpSite 
    WHERE instance_id = :id 
      AND is_active = 1
    LIMIT 1

    ");

    $q->execute([
        ':id' => $instanceId
    ]);

    $row = $q->fetch(PDO::FETCH_ASSOC);

    if (!$row) {

        echo json_encode([

            'success' => false,

            'message' =>
                'No site found for the given instance_id.'
        ]);

        exit;
    }

    $email = $row['email'];

    /*
    ============================================
    | CHECK EXISTING REVIEW
    ============================================
    */

    $check = $pdo->prepare("
        SELECT id
        FROM app_reviews
        WHERE instance_id = :instance_id
        LIMIT 1
    ");

    $check->execute([
        ':instance_id' => $instanceId
    ]);

    $existingReview =
        $check->fetch(PDO::FETCH_ASSOC);

    /*
    ============================================
    | UPDATE EXISTING REVIEW
    ============================================
    */

    if ($existingReview) {

        $update = $pdo->prepare("
            UPDATE app_reviews
            SET
                rating = :rating,
                comment = :comment,
                email = :email,
                ip_address = :ip,
                user_agent = :agent,
                updated_at = NOW()
            WHERE instance_id = :instance_id
        ");

        $update->execute([

            ':instance_id' =>
                $instanceId,

            ':rating' =>
                $rating,

            ':comment' =>
                $comment,

            ':email' =>
                $email,

            ':ip' =>
                $ipAddress,

            ':agent' =>
                $userAgent
        ]);

        echo json_encode([

            'success' => true,

            'message' =>
                'Review updated successfully.'
        ]);

        exit;
    }

    /*
    ============================================
    | INSERT NEW REVIEW
    ============================================
    */

    $insert = $pdo->prepare("
        INSERT INTO app_reviews
        (
            instance_id,
            email,
            rating,
            comment,
            ip_address,
            user_agent,
            created_at,
            updated_at
        )
        VALUES
        (
            :instance_id,
            :email,
            :rating,
            :comment,
            :ip,
            :agent,
            NOW(),
            NOW()
        )
    ");

    $insert->execute([

        ':instance_id' =>
            $instanceId,

        ':email' =>
            $email,

        ':rating' =>
            $rating,

        ':comment' =>
            $comment,

        ':ip' =>
            $ipAddress,

        ':agent' =>
            $userAgent
    ]);

    echo json_encode([

        'success' => true,

        'message' =>
            'Review submitted successfully.'
    ]);

    exit;

} catch (PDOException $e) {

    echo json_encode([

        'success' => false,

        'message' =>
            'Database error: ' .
            $e->getMessage()
    ]);

    exit;
}
?>