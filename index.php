<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

define('ACCESS_CHECK', true);

require_once 'config.php';
require_once 'jdf.php';

function convertToJalali($gregorianDateTime, $format = 'Y/m/d H:i:s') {
    if (!$gregorianDateTime || $gregorianDateTime == '0000-00-00 00:00:00') {
        return '';
    }
    try {
        $timestamp = strtotime($gregorianDateTime);
        if ($timestamp === false) {
            error_log("Error converting Gregorian date to timestamp: " . $gregorianDateTime);
            return '';
        }
        return jdate($format, $timestamp, '', 'Asia/Tehran', 'fa');
    } catch (Exception $e) {
        error_log("Error in convertToJalali: " . $e->getMessage() . " for date: " . $gregorianDateTime);
        return '';
    }
}


function splitPlateToCells($plateStr) {

    $cells = array('', '', '', '', '', '', '', '');

    if (empty($plateStr)) {
        return $cells;
    }

    $plateStr = trim($plateStr);

    if (preg_match('/^(\d{2})([^\d\-])(\d{3})-?(\d{2})$/u', $plateStr, $m)) {

        return array(
            $m[1][0],
            $m[1][1],
            $m[2],
            $m[3][0],
            $m[3][1],
            $m[3][2],
            $m[4][0],
            $m[4][1]
        );
    }

    if (preg_match('/^(\d{3})-?(\d{2})([^\d\-])(\d{2})$/u', $plateStr, $m)) {

        return array(
            $m[4][0],
            $m[4][1],
            $m[3],
            $m[1][0],
            $m[1][1],
            $m[1][2],
            $m[2][0],
            $m[2][1]
        );
    }

    return $cells;
}

// متد هوشمند اصلاح ساختار پلاک خودرو برای نمایش صحیح و راست‌به‌چپ در فایل خروجی اکسل
function formatPlateForExcel($plateStr) {
    if (empty($plateStr)) {
        return '';
    }

    $plateStr = trim($plateStr);

    // بررسی فرمت استاندارد ذخیره شده در دیتابیس (مثال: 12ب345-66)
    if (preg_match('/^(\d{2})([^\d\-]+)(\d{3})-?(\d{2})$/u', $plateStr, $m)) {
        $twoDigits   = $m[1];    // ۲ رقم سمت چپ پلاک
        $letter      = trim($m[2]); // حرف پلاک
        $threeDigits = $m[3];    // ۳ رقم وسط پلاک
        $iranCode    = $m[4];    // ۲ رقم کد ایران (سمت راست پلاک)

        // کاراکترهای پنهان یونیکد برای اجبار اکسل به چیدمان پلاک از چپ به راست
        $lro = "\xE2\x80\xAD"; // Left-to-Right Override
        $pdf = "\xE2\x80\xAC"; // Pop Directional Formatting

        // ساختار بصری نهایی: از چپ به راست [2رقم] [حرف] [3رقم] - [ایران]
        return $lro . $twoDigits . " " . $letter . " " . $threeDigits . " - " . $iranCode . $pdf;
    }

    return $plateStr;
}


// Helper: normalize plate for search (remove spaces, dashes, and non-alphanumeric)
function normalizePlate($plate) {
    $plate = trim($plate);
    $plate = str_replace(['-', ' ', '_'], '', $plate);
    return $plate;
}

// تابع هوشمند برای تبدیل هر نوع فرمت ورودی به فرمت استاندارد 12ب345-66 برای ذخیره در دیتابیس
function standardizePlate($plateStr) {
    $plateStr = trim($plateStr);
    if (empty($plateStr)) {
        return '';
    }

    // ۱. تبدیل کلیه اعداد فارسی و عربی به انگلیسی برای یکپارچگی پایگاه داده
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $plateStr = str_replace($persian, $english, $plateStr);
    $plateStr = str_replace($arabic, $english, $plateStr);

    // ۲. حذف کامل فاصله‌ها، خط تیره‌ها و زیرخط‌ها برای به دست آوردن ساختار خالص متنی پلاک
    $clean = str_replace(['-', ' ', '_'], '', $plateStr);

    // ۳. بررسی فرمت اول: ساختار استاندارد یا جابجا شده (مثال: 12ب34566 یا 12-ب-345-66)
    // تبدیل خروجی به فیلد منظم: 12ب345-66
    if (preg_match('/^(\d{2})([^\d])(\d{3})(\d{2})$/u', $clean, $m)) {
        return $m[1] . $m[2] . $m[3] . '-' . $m[4];
    }

    // ۴. بررسی فرمت دوم: ساختار معکوس شده (مثال: 34566ب12 یا 345-66ب12)
    // مرتب‌سازی اجزا و تبدیل خروجی به فیلد منظم: 12ب345-66
    if (preg_match('/^(\d{3})(\d{2})([^\d])(\d{2})$/u', $clean, $m)) {
        return $m[4] . $m[3] . $m[1] . '-' . $m[2];
    }

    // در صورتی که پلاک ورودی به هر دلیلی خارج از الگوهای فوق بود، همان مقدار پاکسازی شده بازگردانده می‌شود
    return $clean;
}

// -------------------- POST/GET handlers --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // save_visitor
    if (isset($_POST['action']) && $_POST['action'] === 'save_visitor') {
        header('Content-Type: application/json');
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            echo json_encode(['status' => 'error', 'message' => 'نام مراجعه کننده اجباری است.']);
            exit();
        }
        $visitor_id = $_POST['visitor_id'] ?? null;
        $phone = trim($_POST['phone'] ?? '');
        $car_info = trim($_POST['car_info'] ?? '');
        
        // دریافت پلاک و عبور دادن از فیلتر استانداردسازی دقیق پیش از ذخیره در دیتابیس
        $plate = trim($_POST['plate'] ?? '');
        $plate = standardizePlate($plate);
        
        $service_type = trim($_POST['service_type'] ?? 'پارک توسط راننده');
        $payment_method = trim($_POST['payment_method'] ?? ' پرداخت نشده');
        $paid_amount = isset($_POST['paid_amount']) ? floatval($_POST['paid_amount']) : 0;
        $notes = trim($_POST['notes'] ?? '');
        $card_number = null;
        if ($service_type === 'پارک توسط راننده') {
            if (!empty($_POST['exit_time'])) {
                $card_number = null;
            } else {
                $card_number = trim($_POST['card_number'] ?? '');
                if (empty($card_number)) $card_number = null;
            }
        } else {
            $card_number = trim($_POST['card_number'] ?? '');
            if(empty($card_number)) $card_number = null;
        }
        $entry_time = trim($_POST['entry_time'] ?? '');
        if (empty($entry_time)) {
            echo json_encode(['status' => 'error', 'message' => 'ساعت ورود اجباری است.']);
            exit();
        }
        $entry_date = date('Y-m-d', strtotime($entry_time));
        $exit_time = null;
        if (isset($_POST['exit_time']) && !empty($_POST['exit_time'])) {
            $exit_time = trim($_POST['exit_time']);
        }

        $conn->begin_transaction();
        try {
            if ($service_type === 'پارک توسط راننده' && !empty($card_number) && empty($exit_time)) {
                $checkCardSql = "SELECT id FROM visitors WHERE card_number = ? AND exit_time IS NULL";
                if ($visitor_id) $checkCardSql .= " AND id != ?";
                $checkCardSql .= " LIMIT 1 FOR UPDATE";
                $checkStmt = $conn->prepare($checkCardSql);
                if ($visitor_id) {
                    $checkStmt->bind_param("si", $card_number, $visitor_id);
                } else {
                    $checkStmt->bind_param("s", $card_number);
                }
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                if ($checkResult->num_rows > 0) {
                    $conn->rollback();
                    echo json_encode(['status' => 'error', 'message' => 'این شماره کارت در حال حاضر به مراجعه‌کننده دیگری اختصاص داده شده است.']);
                    exit();
                }
                $checkStmt->close();
            }

            if ($visitor_id) {
                $sql = "UPDATE visitors SET name=?, phone=?, car_info=?, plate=?, service_type=?, payment_method=?, paid_amount=?, card_number=?, entry_time=?, exit_time=?, notes=?, entry_date=? WHERE id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssdsssssi", $name, $phone, $car_info, $plate, $service_type, $payment_method, $paid_amount, $card_number, $entry_time, $exit_time, $notes, $entry_date, $visitor_id);
            } else {
                $sql = "INSERT INTO visitors (name, phone, car_info, plate, service_type, payment_method, paid_amount, card_number, entry_time, exit_time, notes, entry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssdsssss", $name, $phone, $car_info, $plate, $service_type, $payment_method, $paid_amount, $card_number, $entry_time, $exit_time, $notes, $entry_date);
            }
            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode(['status' => 'success', 'message' => 'اطلاعات با موفقیت ذخیره شد.']);
            } else {
                throw new Exception('خطا در ذخیره اطلاعات: ' . $stmt->error);
            }
            $stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit();
    }

    // check_plate_info
    if (isset($_POST['action']) && $_POST['action'] === 'check_plate_info') {
        header('Content-Type: application/json');
        $plate_part = trim($_POST['plate_part'] ?? '');
        if (mb_strlen($plate_part) < 4) {
            echo json_encode(null);
            exit();
        }
        $clean_plate = str_replace(['-', ' '], '', $plate_part);
        $variant1 = $plate_part;
        $variant2 = '';
        if (preg_match('/^(\d{2})([^\d])(\d{3})(\d{2})$/u', $clean_plate, $matches)) {
            $variant2 = $matches[3] . '-' . $matches[4] . $matches[2] . $matches[1];
            $variant1 = $matches[1] . $matches[2] . $matches[3] . '-' . $matches[4];
        } elseif (preg_match('/^(\d{3})(\d{2})([^\d])(\d{2})$/u', $clean_plate, $matches)) {
            $variant2 = $matches[4] . $matches[3] . $matches[1] . '-' . $matches[2];
            $variant1 = $matches[1] . '-' . $matches[2] . $matches[3] . $matches[4];
        }
        $sql = "SELECT name, phone, car_info, plate FROM visitors WHERE (plate LIKE ? OR plate LIKE ?) ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $search_term1 = $variant1 . '%';
        $search_term2 = (!empty($variant2)) ? $variant2 . '%' : $variant1 . '%';
        $stmt->bind_param("ss", $search_term1, $search_term2);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            echo json_encode($row);
        } else {
            echo json_encode(null);
        }
        $stmt->close();
        exit();
    }

    // export_excel
    if (isset($_POST['action']) && $_POST['action'] === 'export_excel') {
        require_once 'SimpleXLSXGen.php';
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date   = trim($_POST['end_date'] ?? '');
        if (empty($start_date) || empty($end_date)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'تاریخ شروع و پایان الزامی است.']);
            exit();
        }
        $start_date_shamsi = convertToJalali($start_date, 'Y/m/d');
        $end_date_shamsi   = convertToJalali($end_date, 'Y/m/d');
        $start_safe = str_replace('/', '-', $start_date_shamsi);
        $end_safe   = str_replace('/', '-', $end_date_shamsi);
        $sql = "SELECT id, name, phone, car_info, plate, service_type, payment_method, paid_amount, card_number, entry_time, exit_time, notes
                FROM visitors WHERE entry_date BETWEEN ? AND ? ORDER BY entry_time ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $excelData = [];
        $excelData[] = ['<style bgcolor="#1e3a5f" color="#ffffff" height="40" font-size="20" border="thin"><b><center>گزارش مراجعین بخش VIP پارکینگ بیمارستان نیکان</center></b></style>','','','','','','','','','','',''];
        $excelData[] = ['<style bgcolor="#2c5282" color="#ffffff" height="30" font-size="14" border="thin"><center>از تاریخ ' . $start_date_shamsi . ' تا تاریخ ' . $end_date_shamsi . '</center></style>','','','','','','','','','','',''];
        $excelData[] = array_fill(0, 12, '');
        $headerStyle    = '<style bgcolor="#34495e" color="#ffffff" font-size="12" border="medium"><b><center>';
        $headerStyleEnd = '</center></b></style>';
        $excelData[] = [
            $headerStyle . 'شناسه' . $headerStyleEnd,
            $headerStyle . 'نام' . $headerStyleEnd,
            $headerStyle . 'شماره تماس' . $headerStyleEnd,
            $headerStyle . 'خودرو و رنگ' . $headerStyleEnd,
            $headerStyle . 'پلاک' . $headerStyleEnd,
            $headerStyle . 'نوع سرویس' . $headerStyleEnd,
            $headerStyle . 'شیوه پرداخت' . $headerStyleEnd,
            $headerStyle . 'مبلغ (تومان)' . $headerStyleEnd,
            $headerStyle . 'شماره کارت' . $headerStyleEnd,
            $headerStyle . 'زمان ورود' . $headerStyleEnd,
            $headerStyle . 'زمان خروج' . $headerStyleEnd,
            $headerStyle . 'توضیحات' . $headerStyleEnd
        ];
        $rowIndex = 0;
        $totalAmount = 0;
        $totalRecords = 0;
        while ($row = $result->fetch_assoc()) {
            $rowStyle       = ($rowIndex % 2 == 0) ? '<style bgcolor="#f5f5f5" border="thin">' : '<style bgcolor="#ffffff" border="thin">';
            $rowStyleCenter = ($rowIndex % 2 == 0) ? '<style bgcolor="#f5f5f5" border="thin"><center>' : '<style bgcolor="#ffffff" border="thin"><center>';
            $paid = (int)($row['paid_amount'] ?? 0);
            $totalAmount += $paid;
            $totalRecords++;
            $entryTime = !empty($row['entry_time']) ? convertToJalali($row['entry_time'], 'Y/m/d H:i') : '';
            $exitTime  = !empty($row['exit_time'])  ? convertToJalali($row['exit_time'], 'Y/m/d H:i')  : '';
            $excelData[] = [
                $rowStyleCenter . ($row['id'] ?? '') . '</center></style>',
                $rowStyle . ($row['name'] ?? '') . '</style>',
                $rowStyleCenter . ($row['phone'] ?? '') . '</center></style>',
                $rowStyle . ($row['car_info'] ?? '') . '</style>',
                $rowStyleCenter . formatPlateForExcel($row['plate'] ?? '') . '</center></style>',
                $rowStyle . ($row['service_type'] ?? '') . '</style>',
                $rowStyle . ($row['payment_method'] ?? '') . '</style>',
                $rowStyleCenter . number_format($paid) . '</center></style>',
                $rowStyleCenter . ($row['card_number'] ?? '') . '</center></style>',
                $rowStyleCenter . $entryTime . '</center></style>',
                $rowStyleCenter . $exitTime . '</center></style>',
                $rowStyle . ($row['notes'] ?? '') . '</style>',
            ];
            $rowIndex++;
        }
        $stmt->close();
        $excelData[] = array_fill(0, 12, '');
        $summaryStyle = '<style bgcolor="#27ae60" color="#ffffff" font-size="12" border="medium"><b>';
        $excelData[] = [
            $summaryStyle . 'جمع کل:</b></style>',
            $summaryStyle . 'تعداد: ' . number_format($totalRecords) . ' مورد</b></style>',
            '','','','',
            $summaryStyle . 'مجموع:</b></style>',
            $summaryStyle . number_format($totalAmount) . ' تومان</b></style>',
            '','','',''
        ];
        $excelData[] = array_fill(0, 12, '');
        $excelData[] = [
            '<style color="#7f8c8d" font-size="10"><i>تاریخ تهیه گزارش: ' . convertToJalali(date('Y-m-d H:i:s'), 'Y/m/d H:i:s') . '</i></style>',
            '','','','','','','','','','',''
        ];
        $filename = 'نیکان_گزارش_مراجعین_' . $start_safe . '_تا_' . $end_safe . '.xlsx';
        $xlsx = Shuchkin\SimpleXLSXGen::fromArray($excelData);
        $xlsx->setColWidth(1, 10); $xlsx->setColWidth(2, 25); $xlsx->setColWidth(3, 15);
        $xlsx->setColWidth(4, 20); $xlsx->setColWidth(5, 25); $xlsx->setColWidth(6, 20);
        $xlsx->setColWidth(7, 20); $xlsx->setColWidth(8, 15); $xlsx->setColWidth(9, 15);
        $xlsx->setColWidth(10,20); $xlsx->setColWidth(11,20); $xlsx->setColWidth(12,30);
        $xlsx->mergeCells('A1:L1');
        $xlsx->mergeCells('A2:L2');
        $summaryRow = 6 + $totalRecords;
        $xlsx->mergeCells("B{$summaryRow}:F{$summaryRow}");
        $xlsx->setTitle('گزارش مراجعین VIP');
        $xlsx->setSubject('گزارش پارکینگ بیمارستان نیکان');
        $xlsx->setAuthor('سیستم مدیریت پارکینگ');
        $xlsx->setCompany('بیمارستان نیکان');
        $xlsx->rightToLeft(true);
        $xlsx->downloadAs($filename);
        exit();
    }

    // delete_visitor
    if (isset($_POST['action']) && $_POST['action'] === 'delete_visitor') {
        header('Content-Type: application/json');
        if (isset($_POST['id']) && is_numeric($_POST['id'])) {
            $id = $_POST['id'];
            $stmt = $conn->prepare("DELETE FROM visitors WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'مراجعه کننده با موفقیت حذف شد.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'خطا در حذف: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'شناسه نامعتبر.']);
        }
        exit();
    }

    // register_exit
    if (isset($_POST['action']) && $_POST['action'] === 'register_exit') {
        header('Content-Type: application/json');
        if (isset($_POST['id']) && is_numeric($_POST['id'])) {
            $id = $_POST['id'];
            $exit_time = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE visitors SET exit_time = ?, card_number = NULL WHERE id = ?");
            $stmt->bind_param("si", $exit_time, $id);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'خروج با موفقیت ثبت و کارت آزاد شد.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'خطا در ثبت خروج: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'شناسه نامعتبر است.']);
        }
        exit();
    }

    // save_phone_number
    if (isset($_POST['action']) && $_POST['action'] === 'save_phone_number') {
        header('Content-Type: application/json');
        $department_name = trim($_POST['department_name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $phone_id = $_POST['phone_id'] ?? null;
        if (empty($department_name) || empty($phone_number)) {
            echo json_encode(['status' => 'error', 'message' => 'نام بخش و شماره تلفن اجباری هستند.']);
            exit();
        }
        if ($phone_id) {
            $sql = "UPDATE phone_numbers_directory SET department_name=?, phone_number=?, description=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssi", $department_name, $phone_number, $description, $phone_id);
        } else {
            $sql = "INSERT INTO phone_numbers_directory (department_name, phone_number, description) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sss", $department_name, $phone_number, $description);
        }
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'شماره تلفن با موفقیت ذخیره شد.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'خطا در ذخیره شماره تلفن: ' . $stmt->error]);
        }
        $stmt->close();
        exit();
    }

    // delete_phone_number
    if (isset($_POST['action']) && $_POST['action'] === 'delete_phone_number') {
        header('Content-Type: application/json');
        if (isset($_POST['id']) && is_numeric($_POST['id'])) {
            $id = $_POST['id'];
            $stmt = $conn->prepare("DELETE FROM phone_numbers_directory WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'شماره تلفن با موفقیت حذف شد.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'خطا در حذف شماره تلفن: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'شناسه نامعتبر.']);
        }
        exit();
    }

    // fallback for POST with unknown action
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // fetch_visitors
    if (isset($_GET['action']) && $_GET['action'] === 'fetch_visitors') {
        header('Content-Type: application/json');
        $today = date('Y-m-d');
        $visitors_today = [];
        $visitors_previous_days = [];
        $select_cols = "main_view.*, (
            SELECT COUNT(*) 
            FROM visitors v2 
            WHERE v2.entry_date = main_view.entry_date 
            AND (v2.entry_time < main_view.entry_time OR (v2.entry_time = main_view.entry_time AND v2.id <= main_view.id))
        ) as daily_index";
        $sql_base = "SELECT $select_cols FROM visitors main_view WHERE 1=1";
        $params = [];
        $types = '';
        $start_date = trim($_GET['start_date'] ?? '');
        $end_date = trim($_GET['end_date'] ?? '');
        if (!empty($start_date) && !empty($end_date)) {
            $sql_base .= " AND entry_date BETWEEN ? AND ?";
            $params = array_merge($params, [$start_date, $end_date]);
            $types .= 'ss';
        }
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $searchTerm = '%' . trim($_GET['search']) . '%';
            $sql_base .= " AND (name LIKE ? OR phone LIKE ? OR car_info LIKE ? OR plate LIKE ? OR notes LIKE ? OR card_number LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $types .= 'ssssss';
        }
        if (isset($_GET['service_type']) && !empty($_GET['service_type'])) {
            $sql_base .= " AND service_type = ?";
            $params[] = trim($_GET['service_type']);
            $types .= 's';
        }
        if (isset($_GET['status'])) {
            if ($_GET['status'] === 'present') {
                $sql_base .= " AND exit_time IS NULL";
            } elseif ($_GET['status'] === 'exited') {
                $sql_base .= " AND exit_time IS NOT NULL";
            }
        }
        if (isset($_GET['payment_method']) && !empty($_GET['payment_method'])) {
            $sql_base .= " AND payment_method = ?";
            $params[] = trim($_GET['payment_method']);
            $types .= 's';
        }
        if (isset($_GET['card_number']) && !empty($_GET['card_number'])) {
            $sql_base .= " AND card_number LIKE ?";
            $params[] = '%' . trim($_GET['card_number']) . '%';
            $types .= 's';
        }

        if (empty($start_date) && empty($end_date)) {
            $sql_today_only = $sql_base . " AND entry_date = ? ORDER BY entry_time DESC, id DESC";
            $stmt_today = $conn->prepare($sql_today_only);
            $combined_params_today = array_merge($params, [$today]);
            $combined_types_today = $types . 's';
            if (!empty($combined_params_today)) $stmt_today->bind_param($combined_types_today, ...$combined_params_today);
            $stmt_today->execute();
            $result_today = $stmt_today->get_result();
            while ($row = $result_today->fetch_assoc()) $visitors_today[] = $row;
            $stmt_today->close();

            if (!isset($_GET['status']) || $_GET['status'] !== 'exited') {
                $sql_prev_not_exited = $sql_base . " AND entry_date < ? AND exit_time IS NULL ORDER BY entry_time ASC";
                $stmt_prev = $conn->prepare($sql_prev_not_exited);
                $combined_params_prev = array_merge($params, [$today]);
                $combined_types_prev = $types . 's';
                if (!empty($combined_params_prev)) $stmt_prev->bind_param($combined_types_prev, ...$combined_params_prev);
                $stmt_prev->execute();
                $result_prev = $stmt_prev->get_result();
                while ($row = $result_prev->fetch_assoc()) $visitors_previous_days[] = $row;
                $stmt_prev->close();
            }
        } else {
            $sql_all = $sql_base . " ORDER BY entry_time DESC, id DESC";
            $stmt_all = $conn->prepare($sql_all);
            if (!empty($params)) $stmt_all->bind_param($types, ...$params);
            $stmt_all->execute();
            $result_all = $stmt_all->get_result();
            while ($row = $result_all->fetch_assoc()) $visitors_today[] = $row;
            $stmt_all->close();
        }
        echo json_encode(['today' => $visitors_today, 'previous_days' => $visitors_previous_days]);
        exit();
    }

    // fetch_phone_numbers
    if (isset($_GET['action']) && $_GET['action'] === 'fetch_phone_numbers') {
        header('Content-Type: application/json');
        $searchTerm = '%' . trim($_GET['search'] ?? '') . '%';
        $sql = "SELECT id, department_name, phone_number, description FROM phone_numbers_directory WHERE department_name LIKE ? OR phone_number LIKE ? OR description LIKE ? ORDER BY department_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        $results = [];
        while ($row = $result->fetch_assoc()) $results[] = $row;
        $stmt->close();
        echo json_encode($results);
        exit();
    }

    // fetch_phone_number_by_id
    if (isset($_GET['action']) && $_GET['action'] === 'fetch_phone_number_by_id') {
        header('Content-Type: application/json');
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $phone_id = $_GET['id'];
            $stmt = $conn->prepare("SELECT id, department_name, phone_number, description FROM phone_numbers_directory WHERE id = ?");
            $stmt->bind_param("i", $phone_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $phone_data = $result->fetch_assoc();
            echo json_encode($phone_data);
            $stmt->close();
        } else {
            echo json_encode(null);
        }
        exit();
    }

    // global_search
    if (isset($_GET['action']) && $_GET['action'] === 'global_search') {
        header('Content-Type: application/json');
        $searchTerm = trim($_GET['search'] ?? '');
        $results = [];
        if (empty($searchTerm)) {
            echo json_encode([]);
            exit();
        }
        $searchWildcard = '%' . $searchTerm . '%';
        $sql = "SELECT id, name, phone, car_info, plate, service_type, payment_method, paid_amount, card_number, entry_time, exit_time, notes 
                FROM visitors 
                WHERE name LIKE ? OR phone LIKE ? OR car_info LIKE ? OR plate LIKE ? OR notes LIKE ? OR card_number LIKE ?
                ORDER BY entry_time DESC LIMIT 100";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssss", $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $results[] = $row;
        $stmt->close();
        echo json_encode($results);
        exit();
    }

    // fetch_visitor_by_id
    if (isset($_GET['action']) && $_GET['action'] === 'fetch_visitor_by_id') {
        header('Content-Type: application/json');
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $visitor_id = $_GET['id'];
            $stmt = $conn->prepare("SELECT * FROM visitors WHERE id = ?");
            $stmt->bind_param("i", $visitor_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $visitor = $result->fetch_assoc();
            echo json_encode($visitor);
            $stmt->close();
        } else {
            echo json_encode(null);
        }
        exit();
    }

    // check_card_availability
    if (isset($_GET['action']) && $_GET['action'] === 'check_card_availability') {
        header('Content-Type: application/json');
        $card_number = $_GET['card_number'] ?? '';
        $current_id = $_GET['current_id'] ?? 0;
        $stmt = $conn->prepare("SELECT id FROM visitors WHERE card_number = ? AND exit_time IS NULL AND id != ? LIMIT 1");
        $stmt->bind_param("si", $card_number, $current_id);
        $stmt->execute();
        $result = $stmt->get_result();
        echo json_encode(['available' => $result->num_rows === 0]);
        exit();
    }

    // ***** NEW: fetch plate history *****
    if (isset($_GET['action']) && $_GET['action'] === 'get_plate_history') {
        header('Content-Type: application/json');
        $plate = trim($_GET['plate'] ?? '');
        if (empty($plate)) {
            echo json_encode(['status' => 'error', 'message' => 'پلاک معتبر نیست']);
            exit();
        }
        // Normalize the input plate to match stored plates (removing spaces and dashes)
        $normalizedInput = normalizePlate($plate);
        // Fetch all records that have a plate matching after normalization
        // Since we don't have normalized column, we fetch all and filter in PHP (safer for various formats)
        // But to improve performance, we can use a more flexible SQL condition
        // We'll retrieve all records where plate is not null, then filter by normalized comparison in PHP
        $sql = "SELECT id, name, phone, car_info, plate, service_type, payment_method, paid_amount, entry_time, exit_time, notes 
                FROM visitors 
                WHERE plate IS NOT NULL AND plate != ''
                ORDER BY entry_time DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $normalizedStored = normalizePlate($row['plate']);
            if ($normalizedStored === $normalizedInput) {
                $history[] = $row;
            }
        }
        $stmt->close();
        echo json_encode(['status' => 'success', 'data' => $history]);
        exit();
    }
}

// -------------------- Initial data load for page --------------------
$today = date('Y-m-d');
$initial_visitors_today = [];
$initial_visitors_previous_days = [];
$initial_select_cols = "main_view.*, (
    SELECT COUNT(*) 
    FROM visitors v2 
    WHERE v2.entry_date = main_view.entry_date 
    AND (v2.entry_time < main_view.entry_time OR (v2.entry_time = main_view.entry_time AND v2.id <= main_view.id))
) as daily_index";

$stmt_initial_today = $conn->prepare("SELECT $initial_select_cols FROM visitors main_view WHERE entry_date = ? ORDER BY entry_time DESC, id DESC");
if ($stmt_initial_today) {
    $stmt_initial_today->bind_param("s", $today);
    $stmt_initial_today->execute();
    $result = $stmt_initial_today->get_result();
    while ($row = $result->fetch_assoc()) $initial_visitors_today[] = $row;
    $stmt_initial_today->close();
}

$stmt_initial_prev = $conn->prepare("SELECT $initial_select_cols FROM visitors main_view WHERE entry_date < ? AND exit_time IS NULL ORDER BY entry_time ASC");
if ($stmt_initial_prev) {
    $stmt_initial_prev->bind_param("s", $today);
    $stmt_initial_prev->execute();
    $result = $stmt_initial_prev->get_result();
    while ($row = $result->fetch_assoc()) $initial_visitors_previous_days[] = $row;
    $stmt_initial_prev->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=1200">
    <title>vip ncp</title>
    <link rel="stylesheet" href="css/style.css?v=21011405">
    <link rel="stylesheet" href="font/css/all.css">
    <link rel="stylesheet" href="css/notyf.min.css">
</head>
<body>

<?php include 'header.php'; ?>

<main>
    <section class="registration-form-section">
        <h2 class="title"><i class="fas fa-user-edit"></i> ثبت مراجعه کننده / ویرایش</h2>
        <form id="visitor-form">
            <input type="hidden" id="visitor-id" name="visitor_id">
            <div class="form-row">
                <div class="form-group">
                    <label for="name"><i class="fas fa-user"></i> نام: <span class="required">*</span></label>
                    <input type="text" id="name" name="name" required placeholder="مثال:آقای نادمی">
                </div>
                <div class="form-group">
                    <label for="phone"><i class="fas fa-phone"></i> شماره تماس:</label>
                    <input type="text" id="phone" name="phone" placeholder="مثال: 0939..." inputmode="numeric" pattern="[0-9]{11}" title="11 رقم عددی">
                </div>
                <div class="form-group">
                    <label for="car_info"><i class="fas fa-car"></i> خودرو و رنگ:</label>
                    <input type="text" id="car_info" name="car_info" placeholder="مثال: برلیانس مشکی">
                </div>
                <div class="form-group">
                    <label for="plate"><i class="fas fa-address-card"></i> پلاک:</label>
                    <div class="plate-input-wrapper">
                        <input type="text" id="plate" name="plate" placeholder="مثال: 35ه157-33">
                    </div>
                </div>
                <div class="form-group">
                    <label for="service_type"><i class="fas fa-concierge-bell"></i> نوع سرویس:</label>
                    <select id="service_type" name="service_type">
                        <option value="پارک توسط راننده" selected>پارک توسط راننده</option>
                        <option value="سالن 2E">سالن 2E</option>
                        <option value="سالن 2W">سالن 2W</option>
                        <option value="سالن 1M">سالن 1M</option>
                    </select>
                </div>
                <div class="form-group" id="card-number-group">
                    <label for="card_number"><i class="fas fa-credit-card"></i> شماره کارت:</label>
                    <input type="text" id="card_number" name="card_number" placeholder="شماره کارت VIP">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="payment_method"><i class="fas fa-hand-holding-usd"></i> شیوه پرداخت:</label>
                    <select id="payment_method" name="payment_method">
                        <option value=" پرداخت نشده" selected> پرداخت نشده</option>
                        <option value="دستگاه pos">دستگاه POS</option>
                        <option value="نقد">نقد</option>
                        <option value="کارت به کارت">کارت به کارت</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="paid_amount"><i class="fas fa-coins"></i> مبلغ (تومان):</label>
                    <input type="number" id="paid_amount" name="paid_amount" min="0" step="1000" value="0">
                </div>
                <div class="form-group">
                    <label for="entry_time"><i class="fas fa-sign-in-alt"></i> ساعت ورود: <span class="required">*</span></label>
                    <input type="datetime-local" id="entry_time" name="entry_time" required>
                </div>
                <div class="form-group">
                    <label for="exit_time"><i class="fas fa-sign-out-alt"></i> ساعت خروج:</label>
                    <input type="datetime-local" id="exit_time" name="exit_time">
                </div>
                <div class="form-group notes-group" style="grid-column: span 2;">
                    <label for="notes"><i class="fas fa-comment-alt"></i> توضیحات اضافه:</label>
                    <textarea id="notes" name="notes" placeholder="توضیحات تکمیلی شامل:کارواش،اگر پرداخت مهردرمان شده شماره کارت و ساعت پرداخت و اگر هزینه توسط صندوق دریافت شده اینجا یادداشت شود"></textarea>
                </div>
            </div>
            <div class="form-buttons">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save fa-2x"></i> ثبت / بروزرسانی</button>
                <button type="button" class="btn btn-secondary" id="clear-form-btn"><i class="fas fa-redo fa-2x"></i> پاک کردن فرم</button>
            </div>
        </form>
    </section>

    <hr>

    <section class="filters-search-section">
        <h2 class="icon-title">
            <span class="magnify"><i class="fa-solid fa-magnifying-glass"></i></span>
            <span>جستجو و فیلترها</span>
        </h2>
        <style>
            .icon-title { display: flex; justify-content: center; align-items: center; color:#1b2b49; width:100%; direction:rtl; margin:0; }
            .magnify { display: inline-block; animation: magnifyMove 4s ease-in-out infinite; }
            @keyframes magnifyMove { 0% { transform: translate(1px,8px); } 50% { transform: translate(8px,4px); } 100% { transform: translate(1px,8px); } }
        </style>
        <div class="filters-container">
            <div class="filter-group filter-search-group">
                <label for="search-input"><i class="fas fa-search"></i> جستجو در همه فیلدها:</label>
                <input type="text" id="search-input" placeholder="نام، پلاک، شماره تماس...">
            </div>
            <div class="filter-group filter-card-number-group">
                <label for="filter-card-number"><i class="fas fa-id-card-alt"></i> شماره کارت:</label>
                <input type="text" id="filter-card-number" placeholder="جستجو بر اساس شماره کارت">
            </div>
            <div class="filter-group">
                <label for="start-date"><i class="fas fa-calendar-alt"></i> تاریخ شروع:</label>
                <input type="date" id="start-date" class="date-filter">
            </div>
            <div class="filter-group">
                <label for="end-date"><i class="fas fa-calendar-alt"></i> تاریخ پایان:</label>
                <input type="date" id="end-date" class="date-filter">
            </div>
        </div>
        <div class="filters-second-row">
            <div class="filter-group dropdown-filter">
                <button class="dropdown-toggle"><i class="fas fa-filter"></i> نوع سرویس <i class="fas fa-caret-down"></i></button>
                <div class="dropdown-menu">
                    <input type="radio" id="filter-service-all" name="filter_service_type" value="" checked><label for="filter-service-all">همه</label><br>
                    <input type="radio" id="filter-service-park" name="filter_service_type" value="پارک توسط راننده"><label for="filter-service-park">پارک توسط راننده</label><br>
                    <input type="radio" id="filter-service-2E" name="filter_service_type" value="سالن 2E"><label for="filter-service-2E">سالن 2E</label><br>
                    <input type="radio" id="filter-service-2W" name="filter_service_type" value="سالن 2W"><label for="filter-service-2W">سالن 2W</label><br>
                    <input type="radio" id="filter-service-1M" name="filter_service_type" value="سالن 1M"><label for="filter-service-1M">سالن 1M</label>
                </div>
            </div>
            <div class="filter-group dropdown-filter">
                <button class="dropdown-toggle"><i class="fas fa-clock"></i> وضعیت حضور <i class="fas fa-caret-down"></i></button>
                <div class="dropdown-menu">
                    <input type="radio" id="filter-status-all" name="filter_status" value="" checked><label for="filter-status-all">همه</label><br>
                    <input type="radio" id="filter-status-present" name="filter_status" value="present"><label for="filter-status-present">مراجعین حاضر</label><br>
                    <input type="radio" id="filter-status-exited" name="filter_status" value="exited"><label for="filter-status-exited">مراجعین خروج کرده</label>
                </div>
            </div>
            <div class="filter-group dropdown-filter">
                <button class="dropdown-toggle"><i class="fas fa-money-check-alt"></i> شیوه پرداخت <i class="fas fa-caret-down"></i></button>
                <div class="dropdown-menu">
                    <input type="radio" id="filter-payment-all" name="filter_payment_method" value="" checked><label for="filter-payment-all">همه</label><br>
                    <input type="radio" id="filter-payment-not-paid" name="filter_payment_method" value=" پرداخت نشده"><label for="filter-payment-not-paid">پرداخت نشده</label><br>
                    <input type="radio" id="filter-payment-pos" name="filter_payment_method" value="دستگاه pos"><label for="filter-payment-pos">دستگاه POS</label><br>
                    <input type="radio" id="filter-payment-cash" name="filter_payment_method" value="نقد"><label for="filter-payment-cash">نقد</label><br>
                    <input type="radio" id="filter-payment-card" name="filter_payment_method" value="کارت به کارت"><label for="filter-payment-card">کارت به کارت</label>
                </div>
            </div>
            <div class="filter-buttons">
                <button class="btn btn-export-excel" id="export-excel-btn"><i class="fas fa-file-excel fa-2x"></i> خروجی اکسل</button>
                <button class="btn btn-secondary" id="reset-filters-btn"><i class="fas fa-filter fa-2x"></i> ریست فیلترها</button>
            </div>
        </div>
    </section>

    <hr>

    <section class="visitors-list-section">
        <h2>لیست مراجعین</h2>
        <div class="table-container">
            <table id="visitors-table">
                <thead>
                    <tr>
                        <th>#</th><th>نام</th><th>شماره تماس</th><th>خودرو و رنگ</th><th>پلاک</th>
                        <th>نوع سرویس</th><th>شیوه پرداخت</th><th>مبلغ پرداختی</th><th>شماره کارت</th>
                        <th>ساعت ورود</th><th>ساعت خروج</th><th>توضیحات</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>
</main>

<?php include 'footer.php'; ?>

<div id="date-range-modal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>انتخاب بازه زمانی</h2>
        <div class="modal-form">
            <div class="form-group">
                <label for="modal-start-date"><i class="fas fa-calendar-day"></i> تاریخ شروع:</label>
                <input type="date" id="modal-start-date" class="date-filter">
            </div>
            <div class="form-group">
                <label for="modal-end-date"><i class="fas fa-calendar-day"></i> تاریخ پایان:</label>
                <input type="date" id="modal-end-date" class="date-filter">
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn btn-primary" id="confirm-export-btn"><i class="fas fa-file-excel"></i> دریافت خروجی</button>
                <button type="button" class="btn btn-secondary" id="cancel-export-btn">لغو</button>
            </div>
        </div>
    </div>
</div>

<div id="history-modal" class="modal">
    <div class="modal-content">
        <span class="close-history">&times;</span>
        <h2><i class="fas fa-history"></i> تاریخچه مراجعات این پلاک</h2>
        <div id="history-summary" style="margin: 10px 0; font-weight: bold;"></div>
        
        <div id="history-table-wrapper">
            <table id="history-table">
                <thead>
                    <tr>
                        <th style="padding: 8px;">#</th>
                        <th>نام</th>
                        <th>تاریخ ورود</th>
                        <th>تاریخ خروج</th>
                        <th>نوع سرویس</th>
                        <th>مبلغ (تومان)</th>
                        <th>توضیحات</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        
        <div class="modal-buttons" style="margin-top: 20px;">
            <button type="button" class="btn btn-secondary" id="close-history-modal-btn">بستن</button>
        </div>
    </div>
</div>

<style>
    /* استایل‌های پایه جدول تاریخچه */
    #history-table th, #history-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }
    #history-table tr:nth-child(even){background-color: #f9f9f9;}
    
    /* ترفند ثابت نگه‌داشتن هدر جدول تاریخچه در هنگام اسکرول به پایین */
    #history-table th {
        position: sticky;
        top: 0;
        background-color: #2c5282;
        color: white;
        z-index: 10;
        box-shadow: 0 1px 2px rgba(0,0,0,0.15);
    }

    /* حل مشکل قطعی اسکرول نشدن مودال تاریخچه پلاک خودرو */
    #history-modal {
        overflow-y: auto !important; /* فعال‌سازی اسکرول روی کل پس‌زمینه مودال در صورت نیاز */
    }
    
    #history-modal .modal-content {
        max-width: 90% !important;
        width: 950px !important;
        max-height: 80vh !important; /* محدودیت ارتفاع کل باکس مودال به ۸۰ درصد صفحه */
        overflow-y: auto !important;  /* اجبار مودال به داشتن اسکرول عمودی مجزا در صورت سنگین شدن داده‌ها */
        display: flex !important;
        flex-direction: column !important;
    }

    /* باکس نگه‌دارنده خود جدول برای اسکرول مستقل داخلی */
    #history-table-wrapper {
        max-height: 55vh !important;
        overflow-y: auto !important;
        overflow-x: auto !important;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        margin-top: 10px;
    }
</style>

<script>
    const initialVisitorsToday = <?php echo json_encode($initial_visitors_today ?? []); ?>;
    const initialVisitorsPreviousDays = <?php echo json_encode($initial_visitors_previous_days ?? []); ?>;
</script>

<script src="js/clock.js"></script>
<script src="js/notyf.min.js"></script>
<script src="js/utils.js"></script>
<script src="js/menu.js"></script>
<script src="js/script.js?v=21011406"></script>
</body>
</html>
