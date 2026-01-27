<?php
/**
 * Herramienta para gestionar usuarios
 * PROTEGER CON CONTRASEÑA O ELIMINAR EN PRODUCCIÓN
 */

// Contraseña para acceder a esta herramienta (cámbiala)
$TOOL_PASSWORD = 'herramienta2026';

session_start();

// Verificar acceso
if (!isset($_SESSION['tool_access'])) {
    if (isset($_POST['tool_pass']) && $_POST['tool_pass'] === $TOOL_PASSWORD) {
        $_SESSION['tool_access'] = true;
    } elseif (!isset($_POST['nuevo_usuario'])) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Acceso Restringido</title>
            <style>
                body { font-family: Arial; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                .box { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                input { padding: 10px; margin: 5px 0; width: 200px; }
                button { padding: 10px 20px; background: #002a3f; color: white; border: none; cursor: pointer; }
            </style>
        </head>
        <body>
            <div class="box">
                <h3>🔐 Acceso a Herramienta</h3>
                <form method="POST">
                    <input type="password" name="tool_pass" placeholder="Contraseña de herramienta"><br>
                    <button type="submit">Acceder</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

require_once 'config/db.php';

$message = '';
$sql_output = '';

// Procesar formulario de nuevo usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo_usuario'])) {
    $nuevo_usuario = trim($_POST['nuevo_usuario']);
    $nueva_contrasena = $_POST['nueva_contrasena'];
    
    if (!empty($nuevo_usuario) && !empty($nueva_contrasena)) {
        $hash = password_hash($nueva_contrasena, PASSWORD_BCRYPT);
        
        try {
            // Verificar si usuario existe
            $check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
            $check->execute([$nuevo_usuario]);
            
            if ($check->fetch()) {
                // Actualizar
                $stmt = $pdo->prepare("UPDATE usuarios SET contrasena = ? WHERE usuario = ?");
                $result = $stmt->execute([$hash, $nuevo_usuario]);
                if ($result) {
                    $message = "✅ Usuario '$nuevo_usuario' actualizado correctamente.";
                } else {
                    $message = "❌ Error al actualizar: " . implode(", ", $stmt->errorInfo());
                }
            } else {
                // Insertar
                $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, contrasena) VALUES (?, ?)");
                $result = $stmt->execute([$nuevo_usuario, $hash]);
                if ($result) {
                    $message = "✅ Usuario '$nuevo_usuario' creado correctamente. (ID: " . $pdo->lastInsertId() . ")";
                } else {
                    $message = "❌ Error al insertar: " . implode(", ", $stmt->errorInfo());
                }
            }
        } catch (Exception $e) {
            $message = "❌ Error: " . $e->getMessage();
        }
    } else {
        $message = "❌ Usuario y contraseña son requeridos.";
    }
}

// Obtener usuarios existentes
$usuarios = $pdo->query("SELECT id, usuario FROM usuarios ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Gestión de Usuarios</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; }
        .card { background: white; padding: 25px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { color: #002a3f; margin-top: 0; }
        input { padding: 12px; margin: 5px 0; width: 100%; box-sizing: border-box; border: 1px solid #ddd; border-radius: 5px; }
        button { padding: 12px 25px; background: #002a3f; color: white; border: none; cursor: pointer; border-radius: 5px; font-weight: bold; }
        button:hover { background: #004a6f; }
        .message { padding: 15px; border-radius: 5px; margin-bottom: 15px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .warning { background: #fff3cd; padding: 15px; border-radius: 5px; color: #856404; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>👤 Agregar / Actualizar Usuario</h2>
            
            <?php if ($message): ?>
                <div class="message <?= strpos($message, '✅') !== false ? 'success' : 'error' ?>">
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="text" name="nuevo_usuario" placeholder="Nombre de usuario" required>
                <input type="password" name="nueva_contrasena" placeholder="Contraseña" required>
                <button type="submit">Guardar Usuario</button>
            </form>
            <p style="color: #666; font-size: 12px;">Si el usuario existe, se actualizará su contraseña.</p>
        </div>
        
        <div class="card">
            <h2>📋 Usuarios Registrados</h2>
            <?php if (empty($usuarios)): ?>
                <p>No hay usuarios registrados.</p>
            <?php else: ?>
                <table>
                    <tr><th>ID</th><th>Usuario</th></tr>
                    <?php foreach ($usuarios as $u): ?>
                        <tr><td><?= $u['id'] ?></td><td><?= htmlspecialchars($u['usuario']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
        
        <div class="warning">
            ⚠️ <strong>Seguridad:</strong> Elimina o protege este archivo cuando no lo uses.
            <br><a href="?logout=1">Cerrar sesión de herramienta</a>
        </div>
    </div>
</body>
</html>
<?php
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
