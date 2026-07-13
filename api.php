<?php
// Mute warnings from SoapClient
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

require 'config.php';

try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]);
} catch (\PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'Ошибка подключения к БД. Запустите setup.php или проверьте config.php']));
}

$action = $_GET['action'] ?? '';

// Чтение JSON body
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $_POST = $input;
    }
}

switch ($action) {
    case 'add_wsdl':
        $url = $_POST['url'] ?? '';
        $name = $_POST['name'] ?? '';
        $stmt = $pdo->prepare("INSERT INTO wsdls (name, url) VALUES (?, ?)");
        $stmt->execute([$name, $url]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

    case 'delete_wsdl':
        $id = $_POST['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM wsdls WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    case 'get_wsdls':
        $stmt = $pdo->query("SELECT * FROM wsdls ORDER BY created_at DESC");
        echo json_encode($stmt->fetchAll());
        break;

    case 'parse_wsdl':
        $url = $_GET['url'] ?? '';
        $id = $_GET['id'] ?? 0;
        
        try {
            // Fetch counts
            $stmt = $pdo->prepare("SELECT method_name, COUNT(*) as cnt FROM saved_requests WHERE wsdl_id = ? GROUP BY method_name");
            $stmt->execute([$id]);
            $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $client = new SoapClient($url, ['trace' => 1, 'exceptions' => 0, 'cache_wsdl' => WSDL_CACHE_NONE]);
            $functions = $client->__getFunctions();
            
            $methodNames = [];
            $methods = [];
            if ($functions) {
                foreach ($functions as $func) {
                    if (preg_match('/^\w+\s+([a-zA-Z0-9_]+)\(/', $func, $matches)) {
                        $methodName = $matches[1];
                        if (!in_array($methodName, $methodNames)) {
                            $methodNames[] = $methodName;
                            $methods[] = [
                                'name' => $methodName,
                                'saved_count' => $counts[$methodName] ?? 0
                            ];
                        }
                    }
                }
            }
            echo json_encode(['success' => true, 'methods' => $methods]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'generate_template':
        $url = $_GET['url'] ?? '';
        $method = $_GET['method'] ?? '';
        
        $wsdlContent = @file_get_contents($url, false, stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]));
        $tns = "http://example.com/webservice"; // Default namespace
        $tnsPrefix = "web";
        $rootElement = $method;
        $innerParams = "         <!-- Замените ? на необходимые значения, добавьте нужные параметры -->\n         <!-- <web:paramName>?</web:paramName> -->\n";
        
        if ($wsdlContent) {
            $doc = new DOMDocument();
            @$doc->loadXML($wsdlContent);
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('wsdl', 'http://schemas.xmlsoap.org/wsdl/');
            $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
            
            $tnsAttr = $doc->documentElement->getAttribute('targetNamespace');
            if ($tnsAttr) $tns = $tnsAttr;
            $tnsPrefix = "snt"; // generic prefix
            
            $operation = $xpath->query("//wsdl:portType/wsdl:operation[@name='$method']/wsdl:input")->item(0);
            if ($operation) {
                $messageNameAttr = $operation->getAttribute('message');
                $messageName = explode(':', $messageNameAttr);
                $messageName = end($messageName);
                
                $part = $xpath->query("//wsdl:message[@name='$messageName']/wsdl:part")->item(0);
                if ($part) {
                    $elementAttr = $part->getAttribute('element');
                    if ($elementAttr) {
                        $elementName = explode(':', $elementAttr);
                        $elementName = end($elementName);
                        $rootElement = $elementName;
                        
                        $element = $xpath->query("//xs:schema/xs:element[@name='$elementName']")->item(0);
                        if ($element) {
                            $typeAttr = $element->getAttribute('type');
                            if ($typeAttr) {
                                $typeName = explode(':', $typeAttr);
                                $typeName = end($typeName);
                                
                                $complexType = $xpath->query("//xs:schema/xs:complexType[@name='$typeName']")->item(0);
                                if ($complexType) {
                                    $innerParams = "";
                                    $elements = $xpath->query(".//xs:element", $complexType);
                                    foreach ($elements as $el) {
                                        $elName = $el->getAttribute('name');
                                        $innerParams .= "         <{$elName}>?</{$elName}>\n";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        $template = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $template .= "<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:{$tnsPrefix}=\"{$tns}\">\n";
        $template .= "   <soapenv:Header/>\n";
        $template .= "   <soapenv:Body>\n";
        
        if ($innerParams === "") {
            $template .= "      <{$tnsPrefix}:{$rootElement}/>\n";
        } else {
            $template .= "      <{$tnsPrefix}:{$rootElement}>\n";
            $template .= $innerParams;
            $template .= "      </{$tnsPrefix}:{$rootElement}>\n";
        }
        
        $template .= "   </soapenv:Body>\n";
        $template .= "</soapenv:Envelope>";
        
        echo json_encode(['success' => true, 'template' => $template]);
        break;

    case 'send_request':
        $wsdl_url = $_POST['url'] ?? '';
        $xml = $_POST['xml'] ?? '';
        
        // Remove ?wsdl to get the likely endpoint if not parsed
        $endpoint = preg_replace('/(\?|&)wsdl$/i', '', $wsdl_url);
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: text/xml; charset=utf-8',
            'Content-Length: ' . strlen($xml),
            'SOAPAction: ""'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Workarounds for OpenSSL 3.x and buggy/legacy TLS servers (like esf.kgd.gov.kz)
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        
        // Sometimes setting lower security level is required for KZ GOST or legacy servers
        if (defined('CURLOPT_SSL_CIPHER_LIST')) {
            curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'DEFAULT@SECLEVEL=0');
        }
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) {
            echo json_encode(['success' => false, 'error' => $error]);
        } else {
            if (empty($response) && $http_code >= 400) {
                echo json_encode(['success' => false, 'error' => "HTTP Error: $http_code. Empty response body.\nEndpoint used: $endpoint"]);
            } else {
                echo json_encode(['success' => true, 'response' => empty($response) ? "<!-- Пустой ответ. HTTP Code: $http_code. Endpoint: $endpoint -->" : $response]);
            }
        }
        break;

    case 'save_request':
        $wsdl_id = $_POST['wsdl_id'] ?? 0;
        $method_name = $_POST['method_name'] ?? '';
        $request_name = $_POST['request_name'] ?? '';
        $request_xml = $_POST['request_xml'] ?? '';
        $response_xml = $_POST['response_xml'] ?? null;
        
        $stmt = $pdo->prepare("SELECT id FROM saved_requests WHERE wsdl_id = ? AND method_name = ? AND request_name = ?");
        $stmt->execute([$wsdl_id, $method_name, $request_name]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $stmt = $pdo->prepare("UPDATE saved_requests SET request_xml = ?, response_xml = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$request_xml, $response_xml, $existing['id']]);
            echo json_encode(['success' => true, 'id' => $existing['id'], 'updated' => true]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO saved_requests (wsdl_id, method_name, request_name, request_xml, response_xml) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$wsdl_id, $method_name, $request_name, $request_xml, $response_xml]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'updated' => false]);
        }
        break;

    case 'get_saved_requests':
        $wsdl_id = $_GET['wsdl_id'] ?? 0;
        $method_name = $_GET['method_name'] ?? null;
        
        if ($method_name) {
            $stmt = $pdo->prepare("SELECT * FROM saved_requests WHERE wsdl_id = ? AND method_name = ? ORDER BY created_at DESC");
            $stmt->execute([$wsdl_id, $method_name]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM saved_requests WHERE wsdl_id = ? ORDER BY created_at DESC");
            $stmt->execute([$wsdl_id]);
        }
        echo json_encode($stmt->fetchAll());
        break;
}
?>
