<?php
session_start();
require_once '../php/config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'You must be logged in to book a car'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the data from the AJAX request
    $car_id = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT);
    $pickup_date = filter_input(INPUT_POST, 'pickup_date', FILTER_SANITIZE_STRING);
    $return_date = filter_input(INPUT_POST, 'return_date', FILTER_SANITIZE_STRING);
    $total_price = filter_input(INPUT_POST, 'total_price', FILTER_VALIDATE_FLOAT);
    $user_id = $_SESSION['user_id'];

    error_log("Filtered values: car_id=$car_id, pickup=$pickup_date, return=$return_date, price=$total_price, user=$user_id");

    // Validate input with specific error messages
    if (!$car_id) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid car selected'
        ]);
        exit;
    }

    if (!$pickup_date) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid pickup date'
        ]);
        exit;
    }

    if (!$return_date) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid return date'
        ]);
        exit;
    }

    if (!$total_price) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid total price'
        ]);
        exit;
    }
    // Date format validation
    $pickup_date = date('Y-m-d', strtotime($pickup_date));
    $return_date = date('Y-m-d', strtotime($return_date));

    // check car if available for the selected dates
    $stmt = $conn->prepare("
        SELECT COUNT(*) as booking_count 
        FROM bookings 
        WHERE car_id = ? 
        AND status != 'cancelled' 
        AND ((start_date <= ? AND end_date >= ?) 
            OR (start_date <= ? AND end_date >= ?) 
            OR (start_date >= ? AND end_date <= ?))
    ");
    $stmt->bind_param("issssss", $car_id, $return_date, $pickup_date, $pickup_date, $pickup_date, $pickup_date, $return_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($row['booking_count'] > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'This car is not available for the selected dates'
        ]);
        exit;
    }
    
    // start transaction
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            INSERT INTO bookings (car_id, user_id, start_date, end_date, total_price, status) 
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("iissd", $car_id, $user_id, $pickup_date, $return_date, $total_price);
        $stmt->execute();

        $booking_id = $conn->insert_id;

        $stmt = $conn->prepare("UPDATE cars SET status = 'Booked' WHERE id = ?");
        $stmt->bind_param("i", $car_id);
        $stmt->execute();

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Car booked successfully!',
            'booking_id' => $booking_id
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();

        echo json_encode([
            'success' => false,
            'message' => 'An error occurred while booking the car: ' . $e->getMessage()
        ]);
    }

    exit;
}

header('Location: /');
exit;
