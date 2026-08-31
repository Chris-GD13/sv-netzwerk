<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode([
  'issuer'=>'https://www.sv-netzwerk.eu',
  'authorization_endpoint'=>'https://www.sv-netzwerk.eu/intern/oauth/authorize.php',
  'token_endpoint'=>'https://www.sv-netzwerk.eu/intern/oauth/token.php',
  'registration_endpoint'=>'https://www.sv-netzwerk.eu/intern/oauth/register.php',
  'response_types_supported'=>['code'],
  'grant_types_supported'=>['authorization_code','refresh_token'],
  'token_endpoint_auth_methods_supported'=>['none'],
  'code_challenge_methods_supported'=>['S256'],
  'scopes_supported'=>['cases:read','cases:drafts.write'],
  'authorization_response_iss_parameter_supported'=>true
], JSON_UNESCAPED_SLASHES);
