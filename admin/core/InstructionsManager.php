<?php
// /var/www/html/wix/json-ld/admin/core/InstructionsManager.php

header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';

/**
 * Get all instructions grouped by category
 */
function getInstructions($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT
                c.id   AS category_id,
                c.name AS category_name,
                c.sort_order AS category_sort,
                s.id   AS section_id,
                s.slug,
                s.title,
                s.content,
                s.created_at,
                s.sort_order AS section_sort
            FROM documentation_categories c
            LEFT JOIN documentation s ON c.id = s.category_id
            ORDER BY c.sort_order ASC, s.sort_order ASC
        ");
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($all as $row) {
            $catId = $row['category_id'];
            if (!isset($grouped[$catId])) {
                $grouped[$catId] = [
                    'id'   => $catId,
                    'name' => $row['category_name'],
                    'sections' => []
                ];
            }
            if ($row['section_id'] !== null) {
                $grouped[$catId]['sections'][] = [
                    'id'        => $row['section_id'],
                    'slug'      => $row['slug'],
                    'title'     => $row['title'],
                    'content'   => $row['content'],
                    'created_at'=> $row['created_at'],
                    'sort_order'=> $row['section_sort']
                ];
            }
        }
        echo json_encode(array_values($grouped));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: '.$e->getMessage()]);
    }
}

/**
 * Add or update Section or Category
 */
function handlePostPut($pdo, $data, $method) {
    try {
        if (isset($data['title'], $data['slug'], $data['content'], $data['category_id'])) {
            // Section
            if ($method === 'POST') {
                $stmt = $pdo->prepare("
                    INSERT INTO documentation (category_id, slug, title, content, sort_order, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 999, NOW(), NOW())
                ");
                $stmt->execute([(int)$data['category_id'], $data['slug'], $data['title'], $data['content']]);
                echo json_encode(['success' => true, 'message' => 'Section added successfully.']);
            } elseif ($method === 'PUT' && isset($data['id'])) {
                $stmt = $pdo->prepare("
                    UPDATE documentation SET category_id=?, slug=?, title=?, content=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([(int)$data['category_id'], $data['slug'], $data['title'], $data['content'], (int)$data['id']]);
                echo json_encode(['success' => true, 'message' => 'Section updated successfully.']);
            }
        } elseif (isset($data['name'])) {
            // Category
            if ($method === 'POST') {
                $stmt = $pdo->prepare("
                    INSERT INTO documentation_categories (name, sort_order, created_at, updated_at)
                    VALUES (?, 999, NOW(), NOW())
                ");
                $stmt->execute([$data['name']]);
                echo json_encode(['success' => true, 'message' => 'Category added successfully.']);
            } elseif ($method === 'PUT' && isset($data['id'])) {
                $stmt = $pdo->prepare("
                    UPDATE documentation_categories SET name=?, updated_at=NOW()
                    WHERE id=?
                ");
                $stmt->execute([$data['name'], (int)$data['id']]);
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
 * Delete Section or Category
 */
function handleDelete($pdo, $data) {
    try {
        if (isset($data['id']) && $data['type'] === 'section') {
            $stmt = $pdo->prepare("DELETE FROM documentation WHERE id=?");
            $stmt->execute([(int)$data['id']]);
            echo json_encode(['success' => true, 'message' => 'Section deleted successfully.']);
        } elseif (isset($data['id']) && $data['type'] === 'category') {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM documentation WHERE category_id=?")->execute([(int)$data['id']]);
            $pdo->prepare("DELETE FROM documentation_categories WHERE id=?")->execute([(int)$data['id']]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Category and its sections deleted successfully.']);
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
 * Update sort order
 */
function handlePatch($pdo, $data) {
    try {
        $pdo->beginTransaction();

        if (isset($data['sections']) && is_array($data['sections'])) {
            $stmt = $pdo->prepare("UPDATE documentation SET sort_order=?, category_id=? WHERE id=?");
            foreach ($data['sections'] as $s) {
                $stmt->execute([(int)$s['sort_order'], (int)$s['category_id'], (int)$s['id']]);
            }
            echo json_encode(['success' => true, 'message' => 'Sections order updated successfully.']);
        } elseif (isset($data['categories']) && is_array($data['categories'])) {
            $stmt = $pdo->prepare("UPDATE documentation_categories SET sort_order=? WHERE id=?");
            foreach ($data['categories'] as $c) {
                $stmt->execute([(int)$c['sort_order'], (int)$c['id']]);
            }
            echo json_encode(['success' => true, 'message' => 'Categories order updated successfully.']);
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
        getInstructions($pdo);
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
