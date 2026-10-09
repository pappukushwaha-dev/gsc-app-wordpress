<?php
declare(strict_types=1);

// Suppress ALL output except JSON - do this FIRST
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Start output buffering IMMEDIATELY
if (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Set JSON header early
header('Content-Type: application/json; charset=UTF-8');

// Custom error handler to prevent HTML output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Log the error but don't output it
    error_log("PHP Error [{$errno}]: {$errstr} in {$errfile} on line {$errline}");
    return true; // Suppress default error handler
}, E_ALL);

// Custom exception handler
set_exception_handler(function($exception) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $exception->getMessage(),
    ]);
    exit;
});

// Track if we've sent a response (use global to access in shutdown)
$GLOBALS['_json_response_sent'] = false;

// Shutdown function to catch any final output
register_shutdown_function(function() {
    // If we've already sent JSON, don't interfere
    if (!empty($GLOBALS['_json_response_sent'])) {
        return;
    }
    
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $buffer = ob_get_contents();
        ob_clean();
        
        // Only send JSON if headers haven't been sent and it's not already JSON
        if (!headers_sent() && !preg_match('/^\s*\{/', $buffer)) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Fatal error: ' . $error['message'],
            ]);
            $GLOBALS['_json_response_sent'] = true;
        }
        return;
    }
    
    // Check for any HTML output in buffer (only if we haven't sent JSON yet)
    $buffer = ob_get_contents();
    if (!empty($buffer) && !preg_match('/^\s*\{/', $buffer)) {
        if (preg_match('/<br\s*\/?>/i', $buffer) || preg_match('/<b>/i', $buffer) || preg_match('/<html>/i', $buffer)) {
            error_log('HTML output detected in shutdown: ' . substr($buffer, 0, 500));
            ob_clean();
            
            // Only send JSON if headers haven't been sent
            if (!headers_sent()) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Server error: Unexpected output detected',
                ]);
                $GLOBALS['_json_response_sent'] = true;
            }
        }
    }
});

// Wrap everything in try-catch to handle any errors from includes
// Suppress errors during includes
$oldErrorReporting = error_reporting(0);
$oldDisplayErrors = ini_get('display_errors');
ini_set('display_errors', '0');

try {
    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/google/get_account.php';
    require_once __DIR__ . '/../../includes/google/refresh_token.php';
    require_once __DIR__ . '/../../includes/plan_guard.php';
} catch (Throwable $e) {
    // Restore error settings
    error_reporting($oldErrorReporting);
    if ($oldDisplayErrors !== false) {
        ini_set('display_errors', $oldDisplayErrors);
    }
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Configuration error: ' . $e->getMessage(),
    ]);
    exit;
} finally {
    // Restore error settings
    error_reporting($oldErrorReporting);
    if ($oldDisplayErrors !== false) {
        ini_set('display_errors', $oldDisplayErrors);
    }
}

// Clear any unexpected output from included files
$buffer = ob_get_contents();
if (!empty($buffer)) {
    // Always clear buffer - we only want JSON output
    ob_clean();
    // Log if it was HTML
    if (preg_match('/<br\s*\/?>/i', $buffer) || preg_match('/<b>/i', $buffer) || preg_match('/<html>/i', $buffer)) {
        error_log('Unexpected HTML output from includes: ' . substr($buffer, 0, 500));
    }
}

// ==========================================
// 1. CONFIGURATION: BRAND TERMS
// ==========================================
$BRAND_TERMS = ['mybrand', 'my brand', 'brandname'];
// Helper: position band for charts / striking distance
function getPositionBand(float $pos): string
{
    if ($pos <= 2)  return '1-2';
    if ($pos <= 5)  return '3-5';
    if ($pos <= 10) return '6-10';
    if ($pos <= 20) return '11-20';
    if ($pos <= 50) return '21-50';
    return '51+';
}

// Helper: page type from URL (for cannibalization / page reports)
function detectPageType(string $url): string
{
    $u = strtolower($url);
    if (strpos($u, '/products/') !== false)   return 'Product';
    if (strpos($u, '/collections/') !== false) return 'Collection';
    if (strpos($u, '/blogs/') !== false || strpos($u, '/blog/') !== false) return 'Blog';
    return 'Other';
}

try {
        // ==========================================
        // 2. Parse Input
        // ==========================================
        $raw  = file_get_contents("php://input");
        $data = json_decode($raw, true) ?: [];
        $instanceId = $data['instanceId'] ?? null;
        $range      = $data['range'] ?? "30days";
        $type       = $data['type'] ?? "keywords";
        $startDate  = $data['start'] ?? null;
        $endDate    = $data['end'] ?? null;
        if (!$instanceId) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing instanceId',
            ]);
            exit;
    }

    // ==========================================
    // 2a. PLAN GUARD CHECK (paid / trial gate)
    // ==========================================
    if (!isPlanAllowedForSchemaFeature((string)$instanceId)) {
        ob_clean();
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Feature not available for current plan',
        ]);
        exit;
    }

    // ==========================================
    // 3. Date Logic
    // ==========================================
    $end = date('Y-m-d', strtotime('-3 days')); // Default End is Today
    switch ($range) {
        case 'today':
            $start = date('Y-m-d');
            break;
        case 'yesterday':
            $start = date('Y-m-d', strtotime('-1 day'));
            $end   = date('Y-m-d', strtotime('-1 day'));
            break;
        case '7days':
            $start = date('Y-m-d', strtotime('-7 days'));
            break;
        case '30days':
            $start = date('Y-m-d', strtotime('-30 days'));
            break;
        case 'lastmonth':
            $start = date('Y-m-d', strtotime('first day of last month'));
            $end   = date('Y-m-d', strtotime('last day of last month'));
            break;
        case '90days':
            $start = date('Y-m-d', strtotime('-90 days'));
            break;
        case '12months':
            $start = date('Y-m-d', strtotime('-12 months'));
            break;
        case '365days':
            $start = date('Y-m-d', strtotime('-365 days'));
            break;
        case 'lastyear':
            $start = date('Y-01-01', strtotime('last year'));
            $end   = date('Y-12-31', strtotime('last year'));
            break;
        case 'custom':
            if (!$startDate || !$endDate) {
                throw new Exception('Missing custom date range');
            }
            $start = $startDate;
            $end   = date('Y-m-d', strtotime($endDate . ' -3 days'));
            break;
        default:
            $start = date('Y-m-d', strtotime('-30 days'));
            break;
    }

    // ==========================================
    // 4. Dimensions Setup
    // ==========================================
    $dimensions = ['query'];
    switch ($type) {
        case 'dates':
            $dimensions = ['date'];
            break;
        case 'pages':
            $dimensions = ['page'];
            break;
        case 'countries':
            $dimensions = ['country'];
            break;
        case 'devices':
            $dimensions = ['device'];
            break;
        case 'appearance':
            $dimensions = ['searchAppearance'];
            break;
        case 'qp':
            $dimensions = ['query', 'page'];
            break;
        default:
            $dimensions = ['query'];
            break;
    }

    // ==========================================
    // 5. AUTHENTICATION & TOKEN HANDLING
    // ==========================================
    $google = getGoogleAccountByInstance($instanceId);
    if (!$google || empty($google['access_token'])) {
        throw new Exception('Google account not connected');
    }
    $accessToken = $google['access_token'];
    // Check if token is expired (with 5 minute buffer)
    if (!empty($google['token_expires_at'])) {
        $expiresAtTimestamp = strtotime($google['token_expires_at']);
        if ($expiresAtTimestamp <= (time() + 300)) {
            $refreshResult = refreshGoogleToken($google);
        if (
            empty($refreshResult) ||
            empty($refreshResult['success']) ||
            empty($refreshResult['access_token'])
        ) {
            error_log('REFRESH TOKEN FAILED: ' . print_r($refreshResult, true));
            throw new Exception(
                'Google authorization expired. Please reconnect your Google account.'
            );
        }
            $accessToken = $refreshResult['access_token'];
            $google['access_token'] = $accessToken;
        }
    }

    // ==========================================
    // 6. GET SITE URL
    // ==========================================
    $stmt = $pdo->prepare("SELECT site_url FROM gsc_domain_verifications WHERE instance_id = ? LIMIT 1");
    $stmt->execute([$instanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Domain not found');
    }
    $rawSite = trim($row['site_url']);
    $variants = [];
    // Clean domain
    $clean = preg_replace('#^https?://#', '', $rawSite);
    $clean = preg_replace('#^www\.#', '', $clean);
    $clean = rtrim($clean, '/');
    
    // Try URL-prefix properties first (more commonly used and easier to verify)
    $variants[] = 'https://' . $clean . '/';
    $variants[] = 'https://www.' . $clean . '/';
    // Then try domain property (requires domain verification)
    $variants[] = 'sc-domain:' . $clean;
    
    $siteVariants = array_unique($variants);
    // ==========================================
    // 7. API CALL FUNCTION
    // ==========================================
    function callGscApi(string $url, string $token, string $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS     => $payload
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        return [
            'code'       => $httpCode,
            'response'   => $response,
            'curl_error' => $curlError
        ];
        }
        $payload = json_encode([
            "startDate"  => $start,
            "endDate"    => $end,
            "dimensions" => $dimensions,
            "rowLimit"   => 5000
        ]);
        // // --- ATTEMPT 1 ---    
        $successJson = null;
        $lastError   = null;
        foreach ($siteVariants as $siteUrl) {
        $apiUrl = "https://searchconsole.googleapis.com/webmasters/v3/sites/" .
                urlencode($siteUrl) .
                "/searchAnalytics/query";
        $result = callGscApi($apiUrl, $accessToken, $payload);
        if ($result['code'] === 401) {
            $refreshResult = refreshGoogleToken($google);
            if (!empty($refreshResult['success']) && !empty($refreshResult['access_token'])) {
                $accessToken = $refreshResult['access_token'];
                $result      = callGscApi($apiUrl, $accessToken, $payload);
            }
        }
        if ($result['code'] === 200) {
            $successJson = json_decode($result['response'], true);
            break;
        }
        
        // Parse error response for better error messages
        $errorData = json_decode($result['response'], true);
        if ($result['code'] === 403 && isset($errorData['error'])) {
            $errorMessage = $errorData['error']['message'] ?? $errorData['error']['status'] ?? 'Permission denied';
            $lastError = [
                'code' => 403,
                'message' => "GSC API (403): {$errorMessage}",
                'site' => $siteUrl,
                'response' => $result['response']
            ];
        } else {
            $lastError = $result;
        }
        }
        if (!$successJson) {
            // Build a more helpful error message
            $errorMsg = "GSC API failed for all properties.";
            if (isset($lastError['message'])) {
                $errorMsg = $lastError['message'];
            } elseif (isset($lastError['response'])) {
                $errorData = json_decode($lastError['response'], true);
                if (isset($errorData['error']['message'])) {
                    $errorMsg = "GSC API Error: " . $errorData['error']['message'];
                } else {
                    $errorMsg = "GSC API failed. Last error: " . substr($lastError['response'], 0, 200);
                }
            }
            
            // Add helpful guidance for 403 errors
            if (isset($lastError['code']) && $lastError['code'] === 403) {
                $errorMsg .= " Please ensure: 1) The domain is verified in Google Search Console, 2) The connected Google account has access to this property, 3) Try using URL-prefix property (https://...) instead of domain property (sc-domain:...).";
            }
            
            throw new Exception($errorMsg);
        }
        $json = $successJson;

    // ==========================================
    // 8. PROCESS DATA (BASE TABLE)
    // ==========================================
    $rowsOutput = [];
    $totals     = ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
    $brandStats = [
        'branded' => ['clicks' => 0, 'impressions' => 0, 'count' => 0],
        'non_branded' => ['clicks' => 0, 'impressions' => 0, 'count' => 0],
    ];

    $positionBandsAgg   = [];
    $brandKeywords      = [];
    $nonBrandKeywords   = [];
    $strikingDistance   = [];
    $cannibalizationRaw = [];
    $count = 0;

    if (!empty($json['rows'])) {
        foreach ($json['rows'] as $row) {
            $rClicks = $row['clicks'] ?? 0;
            $rImpr   = $row['impressions'] ?? 0;
            $rCtr    = round(($row['ctr'] ?? 0) * 100, 2);
            $rPos    = round($row['position'] ?? 0, 1);
            $keys    = $row['keys'] ?? [];
            $safeKeys = array_map('htmlspecialchars', $keys);
            $isBranded = false;
            if (in_array('query', $dimensions, true)) {
                $queryStr = strtolower($keys[0] ?? '');
                foreach ($BRAND_TERMS as $term) {
                    $term = strtolower($term);
                    if ($term !== '' && strpos($queryStr, $term) !== false) {
                        $isBranded = true;
                        break;
                    }
                }
                if ($isBranded) {
                    $brandStats['branded']['clicks']      += $rClicks;
                    $brandStats['branded']['impressions'] += $rImpr;
                    $brandStats['branded']['count']++;
                } else {
                    $brandStats['non_branded']['clicks']      += $rClicks;
                    $brandStats['non_branded']['impressions'] += $rImpr;
                    $brandStats['non_branded']['count']++;
                }
            }

            $band = getPositionBand($rPos);
            if (!isset($positionBandsAgg[$band])) {
                $positionBandsAgg[$band] = [
                    'band'        => $band,
                    'rows'        => 0,
                    'clicks'      => 0,
                    'impressions' => 0,
                ];
            }
            $positionBandsAgg[$band]['rows']++;
            $positionBandsAgg[$band]['clicks']      += $rClicks;
            $positionBandsAgg[$band]['impressions'] += $rImpr;

            if (in_array('query', $dimensions, true) && $rPos >= 11 && $rPos <= 20) {
                $sdRow = [
                    'keys'         => $safeKeys,
                    'clicks'       => $rClicks,
                    'impressions'  => $rImpr,
                    'ctr'          => $rCtr,
                    'position'     => $rPos,
                    'position_band'=> $band,
                    'isBranded'    => $isBranded,
                ];
                $strikingDistance[] = $sdRow;
            }

            if (in_array('query', $dimensions, true)) {
                $kwRow = [
                    'keys'         => $safeKeys,
                    'clicks'       => $rClicks,
                    'impressions'  => $rImpr,
                    'ctr'          => $rCtr,
                    'position'     => $rPos,
                    'position_band'=> $band,
                    'isBranded'    => $isBranded,
                ];
                if ($isBranded) {
                    $brandKeywords[] = $kwRow;
                } else {
                    $nonBrandKeywords[] = $kwRow;
                }
            }

            if ($dimensions === ['query', 'page'] && count($keys) >= 2) {
                $query = $safeKeys[0];
                $page  = $safeKeys[1];
                $pageType = detectPageType($page);
                if (!isset($cannibalizationRaw[$query])) {
                    $cannibalizationRaw[$query] = [];
                }
                $cannibalizationRaw[$query][] = [
                    'page'       => $page,
                    'page_type'  => $pageType,
                    'clicks'     => $rClicks,
                    'impressions'=> $rImpr,
                    'position'   => $rPos,
                ];
            }

            $rowsOutput[] = [
                'keys'         => $safeKeys,
                'clicks'       => $rClicks,
                'impressions'  => $rImpr,
                'ctr'          => $rCtr,
                'position'     => $rPos,
                'isBranded'    => $isBranded,
                'position_band'=> $band,
            ];
            $totals['clicks']      += $rClicks;
            $totals['impressions'] += $rImpr;
            $totals['ctr']         += $rCtr;
            $totals['position']    += $rPos;
            $count++;
        }
    }
    $avgCtr = $count > 0 ? round($totals['ctr'] / $count, 2) : 0.0;
    $avgPos = $count > 0 ? round($totals['position'] / $count, 1) : 0.0;

    // ==========================================
    // 9. Cannibalization post-processing (qp type)
    // ==========================================
    $cannibalization = [];
    if (!empty($cannibalizationRaw)) {
        foreach ($cannibalizationRaw as $keyword => $pagesArr) {
            if (count($pagesArr) <= 1) {
                continue;
            }
            usort($pagesArr, function ($a, $b) {
                return $b['impressions'] <=> $a['impressions'];
            });
            $topImpr    = $pagesArr[0]['impressions'] ?: 1;
            $secondImpr = $pagesArr[1]['impressions'] ?? 0;
            $gap        = $topImpr > 0 ? round((($topImpr - $secondImpr) / $topImpr) * 100) : 0;
            $cannibalization[] = [
                'keyword' => $keyword,
                'pages'   => $pagesArr,
                'gap'     => $gap,
            ];
        }
    }

    $bandsOrder = ['1-2', '3-5', '6-10', '11-20', '21-50', '51+'];
    $positionBands = [];
    foreach ($bandsOrder as $b) {
        if (isset($positionBandsAgg[$b])) {
            $positionBands[] = $positionBandsAgg[$b];
        }
    }

    usort($strikingDistance, function ($a, $b) {
        return $a['position'] <=> $b['position'];
    });

    // ==========================================
    // 10. Final Response
    // ==========================================
    // Clear any buffered output before sending JSON
    $finalBuffer = ob_get_contents();
    if (!empty($finalBuffer)) {
        ob_clean();
        // Log if there was any unexpected output
        if (preg_match('/<br\s*\/?>/i', $finalBuffer) || preg_match('/<b>/i', $finalBuffer)) {
            error_log('Unexpected output before JSON response: ' . substr($finalBuffer, 0, 500));
        }
    } else {
        ob_clean();
    }
    
    $GLOBALS['_json_response_sent'] = true;
    echo json_encode([
        'success' => true,
        'range'   => [$start, $end],

        'metrics' => [
            'clicks'      => $totals['clicks'],
            'impressions' => $totals['impressions'],
            'ctr'         => $avgCtr,
            'position'    => $avgPos,
            'brand_split' => [
                'branded_clicks'     => $brandStats['branded']['clicks'],
                'non_branded_clicks' => $brandStats['non_branded']['clicks'],
                'branded_impr'       => $brandStats['branded']['impressions'],
                'non_branded_impr'   => $brandStats['non_branded']['impressions'],
            ],
        ],

        'rows' => $rowsOutput,

        'extra' => [
            'position_bands'     => $positionBands,
            'striking_distance'  => $strikingDistance,
            'brand_keywords'     => $brandKeywords,
            'non_brand_keywords' => $nonBrandKeywords,
            'cannibalization'    => $cannibalization,
        ],
    ]);
    
    // End output buffering to ensure clean output
    if (ob_get_level() > 0) {
        ob_end_flush();
    }

} catch (Exception $e) {
    // Clear any buffered output before sending error JSON
    $errorBuffer = ob_get_contents();
    if (!empty($errorBuffer)) {
        ob_clean();
        // Log if there was HTML output
        if (preg_match('/<br\s*\/?>/i', $errorBuffer) || preg_match('/<b>/i', $errorBuffer)) {
            error_log('HTML output in exception handler: ' . substr($errorBuffer, 0, 500));
        }
    } else {
        ob_clean();
    }
    
    $GLOBALS['_json_response_sent'] = true;
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ]);
    
    // End output buffering
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    exit;
}
