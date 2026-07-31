 <?php
// Убедитесь, что папка "uploads" существует и имеет права на запись
$uploadDir = 'uploads/';
$uploadFile = $uploadDir . basename($_FILES['upload']['name']);

header('Content-Type: application/json');

// Проверяем, существует ли файл
if (file_exists($uploadFile)) {
    // Если файл существует, возвращаем его URL
    echo json_encode([
        "uploaded" => 1,
        "fileName" => basename($_FILES['upload']['name']),
        "url" => 'https://shiduh.org/ckeditor/' . $uploadDir . basename($_FILES['upload']['name'])
    ]);
    exit;
}

// Перемещаем загруженный файл в папку
if (move_uploaded_file($_FILES['upload']['tmp_name'], $uploadFile)) {
    $response = [
        "uploaded" => 1,
        "fileName" => $_FILES['upload']['name'],
        "url" => 'https://shiduh.org/ckeditor/' . $uploadDir . $_FILES['upload']['name']
    ];
} else {
    $response = ["uploaded" => 0, "error" => ["message" => "Ошибка загрузки"]];
}


echo json_encode($response);
?>