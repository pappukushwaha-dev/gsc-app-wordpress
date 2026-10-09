<?php
// Include the database connection
require_once __DIR__ . '/../../../includes/config.php';

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST data
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate the input data
    if (isset($data['name'], $data['category_id'], $data['subject'], $data['body'])) {
        // Sanitize and trim inputs but don't escape the HTML tags
        $name = trim($data['name']);
        $category_id = (int)$data['category_id'];  // Ensure category_id is an integer
        $subject = trim($data['subject']);
        $body = trim($data['body']);  // Don't escape body, keep raw HTML

        // Check if the category exists
        $query = "SELECT id FROM email_categories WHERE id = :category_id";
        $stmt = $pdo->prepare($query);
        $stmt->execute(['category_id' => $category_id]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($category) {
            // If template ID is provided, update the existing template
            if (isset($data['id']) && !empty($data['id'])) {
                $template_id = (int)$data['id'];
                $query = "UPDATE email_templates SET name = :name, category_id = :category_id, subject = :subject, body = :body WHERE id = :template_id";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':category_id', $category_id);
                $stmt->bindParam(':subject', $subject);
                $stmt->bindParam(':body', $body);
                $stmt->bindParam(':template_id', $template_id);

                try {
                    if ($stmt->execute()) {
                        echo json_encode(['success' => true, 'message' => 'Template updated successfully!']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to update template.']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                }
            } else {
                // Insert a new template
                $query = "INSERT INTO email_templates (name, category_id, subject, body) VALUES (:name, :category_id, :subject, :body)";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':category_id', $category_id);
                $stmt->bindParam(':subject', $subject);
                $stmt->bindParam(':body', $body);

                try {
                    if ($stmt->execute()) {
                        echo json_encode(['success' => true, 'message' => 'Template saved successfully!']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Failed to save template.']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                }
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Category not found.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing required fields: name, category_id, subject, or body.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Please use POST.']);
}
?>
