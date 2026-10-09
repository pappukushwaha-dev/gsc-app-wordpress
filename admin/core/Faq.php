<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';

/**
 * Get all FAQs grouped by category
 */
function getFaqs($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT
                fc.id AS category_id,
                fc.name AS category_name,
                fc.sort_order AS category_sort,
                f.id AS faq_id,
                f.question,
                f.answer,
                f.created_at,
                f.sort_order AS faq_sort
            FROM faq_categories fc
            LEFT JOIN faqs f ON fc.id = f.category_id
            ORDER BY fc.sort_order ASC, f.sort_order ASC
        ");
        $allFaqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $faqsByCategory = [];
        foreach ($allFaqs as $row) {
            $catId = $row['category_id'];
            if (!isset($faqsByCategory[$catId])) {
                $faqsByCategory[$catId] = [
                    'id'    => $catId,
                    'name'  => $row['category_name'],
                    'faqs'  => []
                ];
            }
            if ($row['faq_id'] !== null) {
                $faqsByCategory[$catId]['faqs'][] = [
                    'id'        => $row['faq_id'],
                    'question'  => $row['question'],
                    'answer'    => $row['answer'],
                    'created_at'=> $row['created_at'],
                    'sort_order'=> $row['faq_sort']
                ];
            }
        }
        echo json_encode(array_values($faqsByCategory));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: '.$e->getMessage()]);
    }
}

/**
 * Add or update FAQ/Category
 */
function handlePostPut($pdo, $data, $method) {
    try {
        if (isset($data['question'], $data['answer'], $data['category_id'])) {
            // FAQ
            $question   = trim($data['question']);
            $answer     = trim($data['answer']);
            $categoryId = (int)$data['category_id'];

            if ($method === 'POST') {
                $stmt = $pdo->prepare("
                    INSERT INTO faqs (question, answer, category_id, sort_order, created_at, updated_at)
                    VALUES (?, ?, ?, 999, NOW(), NOW())
                ");
                $stmt->execute([$question, $answer, $categoryId]);
                echo json_encode(['success' => true, 'message' => 'FAQ added successfully.']);
     } elseif ($method === 'PUT' && isset($data['id'])) {
    $faqId = (int)$data['id'];
    $stmt = $pdo->prepare("
        UPDATE faqs 
        SET question = ?, answer = ?, category_id = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$question, $answer, $categoryId, $faqId]);
    echo json_encode(['success' => true, 'message' => 'FAQ updated successfully.']);
}


        } elseif (isset($data['name'])) {
            // Category
            $categoryName = trim($data['name']);
            if ($method === 'POST') {
                $stmt = $pdo->prepare("
                    INSERT INTO faq_categories (name, sort_order, created_at, updated_at)
                    VALUES (?, 999, NOW(), NOW())
                ");
                $stmt->execute([$categoryName]);
                echo json_encode(['success' => true, 'message' => 'Category added successfully.']);
            } elseif ($method === 'PUT' && isset($data['id'])) {
                $catId = (int)$data['id'];
                $stmt = $pdo->prepare("
                    UPDATE faq_categories SET name = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$categoryName, $catId]);
                echo json_encode(['success' => true, 'message' => 'Category updated successfully.']);
            }

        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: '.$e->getMessage()]);
    }
}

/**
 * Delete FAQ or Category
 */
function handleDelete($pdo, $data) {
    try {
        if (isset($data['id'])) {
            $stmt = $pdo->prepare("DELETE FROM faqs WHERE id = ?");
            $stmt->execute([(int)$data['id']]);
            echo json_encode(['success' => true, 'message' => 'FAQ deleted successfully.']);
        } elseif (isset($data['category_id'])) {
            $pdo->beginTransaction();
            $stmt1 = $pdo->prepare("DELETE FROM faqs WHERE category_id = ?");
            $stmt1->execute([(int)$data['category_id']]);
            $stmt2 = $pdo->prepare("DELETE FROM faq_categories WHERE id = ?");
            $stmt2->execute([(int)$data['category_id']]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Category and its FAQs deleted successfully.']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid delete request.']);
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: '.$e->getMessage()]);
    }
}

/**
 * Update sort_order for FAQs or Categories (PATCH)
 */
function handlePatch($pdo, $data) {
    try {
        // Use a transaction for integrity
        $pdo->beginTransaction();

        if (isset($data['faqs']) && is_array($data['faqs'])) {
            $stmt = $pdo->prepare("UPDATE faqs SET sort_order = ?, category_id = ? WHERE id = ?");
            foreach ($data['faqs'] as $faq) {
                $stmt->execute([(int)$faq['sort_order'], (int)$faq['category_id'], (int)$faq['id']]);
            }
            echo json_encode(['success' => true, 'message' => 'FAQ order and categories updated successfully.']);
        } elseif (isset($data['categories']) && is_array($data['categories'])) {
            $stmt = $pdo->prepare("UPDATE faq_categories SET sort_order = ? WHERE id = ?");
            foreach ($data['categories'] as $cat) {
                $stmt->execute([(int)$cat['sort_order'], (int)$cat['id']]);
            }
            echo json_encode(['success' => true, 'message' => 'Category order updated successfully.']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid data for reordering.']);
        }
        
        $pdo->commit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: '.$e->getMessage()]);
    }
}

/**
 * Router
 */
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getFaqs($pdo);
        break;
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        handlePostPut($pdo, $data, 'POST');
        break;
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        handlePostPut($pdo, $data, 'PUT');
        break;
    case 'PATCH':
        $data = json_decode(file_get_contents('php://input'), true);
        handlePatch($pdo, $data);
        break;
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);
        handleDelete($pdo, $data);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
        break;
}