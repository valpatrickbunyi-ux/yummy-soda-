<?php
if (!function_exists('e')) {
  function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
  function money($n) { return number_format((float)$n, 2); }
}