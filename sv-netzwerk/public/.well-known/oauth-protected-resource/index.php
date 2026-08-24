<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode([
  'resource'=>'https://www.sv-netzwerk.eu/intern/mcp/',
  'authorization_servers'=>['https://www.sv-netzwerk.eu'],
  'scopes_supported'=>['cases:read'],
  'resource_documentation'=>'https://www.sv-netzwerk.eu/intern/versicherungsfaelle/'
], JSON_UNESCAPED_SLASHES);
