<?php
if (!headers_sent()) { ob_start(); }
session_start();
error_reporting(E_ALL); ini_set('display_errors',0); ini_set('log_errors',1); ini_set('error_log', __DIR__.'/panel_error.log');
require_once __DIR__.'/db.php';
$USER_ID = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($USER_ID<=0) { try{ $r=$pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1"); $row=$r?$r->fetch(PDO::FETCH_ASSOC):null; if($row)$USER_ID=(int)$row['id']; }catch(Throwable $e){} }
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
?>