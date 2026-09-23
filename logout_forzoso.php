<?php
session_start();
session_unset();
session_destroy();
setcookie(session_name(), '', time() - 3600, '/');
echo "Sesión destruida correctamente. <a href='login.php'>Haz clic aquí para ir al Login limpio</a>";
?>