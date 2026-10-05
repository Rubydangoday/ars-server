<?php
require __DIR__ . '/../lib/student.php';
unset($_SESSION['student']);
header('Location: index.php');
