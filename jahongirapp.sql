-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--rr
-- Host: 127.0.0.1
-- Generation Time: Sep 07, 2024 at 09:29 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `jahongirapp`
--

-- --------------------------------------------------------

--
-- Table structure for table `cars`
--

CREATE TABLE `cars` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `model` varchar(255) NOT NULL,
  `number_seats` int(11) NOT NULL,
  `number_luggage` int(11) NOT NULL,
  `image` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cars`
--

INSERT INTO `cars` (`id`, `created_at`, `updated_at`, `model`, `number_seats`, `number_luggage`, `image`) VALUES
(1, '2024-09-04 03:12:05', '2024-09-04 03:12:05', 'Cobalt', 3, 3, '01J6Y1EFGHK038PY95Q5PWFRCS.png');

-- --------------------------------------------------------

--
-- Table structure for table `car_driver`
--

CREATE TABLE `car_driver` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `car_id` bigint(20) UNSIGNED NOT NULL,
  `driver_id` bigint(20) UNSIGNED NOT NULL,
  `car_plate` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `car_driver`
--

INSERT INTO `car_driver` (`id`, `created_at`, `updated_at`, `car_id`, `driver_id`, `car_plate`) VALUES
(1, '2024-09-04 03:13:24', '2024-09-04 03:13:24', 1, 1, '30AC56454'),
(2, '2024-09-05 04:57:07', '2024-09-05 04:57:07', 1, 2, 'Id fugit repudiandaAC457');

-- --------------------------------------------------------

--
-- Table structure for table `drivers`
--

CREATE TABLE `drivers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone01` varchar(255) NOT NULL,
  `phone02` varchar(255) DEFAULT NULL,
  `fuel_type` varchar(255) NOT NULL,
  `driver_image` varchar(255) DEFAULT NULL,
  `full_name` varchar(255) GENERATED ALWAYS AS (concat(`first_name`,' ',`last_name`)) VIRTUAL,
  `extra_details` text DEFAULT NULL,
  `address_city` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `drivers`
--

INSERT INTO `drivers` (`id`, `created_at`, `updated_at`, `first_name`, `last_name`, `email`, `phone01`, `phone02`, `fuel_type`, `driver_image`, `extra_details`, `address_city`) VALUES
(1, '2024-09-04 03:13:24', '2024-09-04 03:13:24', 'Hasan', 'Muzaffarov', 'hasan@hasan.com', '+99891554545', NULL, 'propane', '01J6Y1GWSEJRF4ASY74DNV1KD4.jfif', 'dfdsf', 'Obolin'),
(2, '2024-09-05 04:57:07', '2024-09-05 04:57:07', 'Knox', 'Holden', 'bityvawaty@mailinator.com', '+1 919 592-3244', NULL, 'propane', '01J70SVH3VHH8F828FE9385PX1.jpg', 'Minus saepe reprehen', 'Cumque vel incididun');

-- --------------------------------------------------------

--
-- Table structure for table `driver_tour_booking`
--

CREATE TABLE `driver_tour_booking` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `driver_id` bigint(20) UNSIGNED NOT NULL,
  `tour_booking_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `driver_tour_booking`
--

INSERT INTO `driver_tour_booking` (`id`, `created_at`, `updated_at`, `driver_id`, `tour_booking_id`) VALUES
(1, NULL, NULL, 1, 1),
(2, NULL, NULL, 1, 2),
(3, NULL, NULL, 1, 3);

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `guests`
--

CREATE TABLE `guests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `country` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `full_name` varchar(255) GENERATED ALWAYS AS (concat(`first_name`,' ',`last_name`)) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `guests`
--

INSERT INTO `guests` (`id`, `created_at`, `updated_at`, `first_name`, `last_name`, `email`, `country`, `phone`) VALUES
(2, '2024-09-05 05:56:14', '2024-09-05 05:56:14', 'Kelsey', 'Talley', 'lasoxi@mailinator.com', 'USA', '+1 697 972-7452');

-- --------------------------------------------------------

--
-- Table structure for table `guest_tour_booking`
--

CREATE TABLE `guest_tour_booking` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `guest_id` bigint(20) UNSIGNED NOT NULL,
  `tour_booking_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `guest_tour_booking`
--

INSERT INTO `guest_tour_booking` (`id`, `created_at`, `updated_at`, `guest_id`, `tour_booking_id`) VALUES
(1, NULL, NULL, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `guides`
--

CREATE TABLE `guides` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone01` varchar(255) NOT NULL,
  `phone02` varchar(255) DEFAULT NULL,
  `guide_image` varchar(255) NOT NULL,
  `full_name` varchar(255) GENERATED ALWAYS AS (concat(`first_name`,' ',`last_name`)) VIRTUAL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `guides`
--

INSERT INTO `guides` (`id`, `created_at`, `updated_at`, `first_name`, `last_name`, `email`, `phone01`, `phone02`, `guide_image`) VALUES
(1, '2024-09-04 03:18:33', '2024-09-04 03:18:33', 'Melvin', 'Fuentes', 'verun@mailinator.com', '+1 131 229-4307', NULL, '01J6Y1TA67KR3K8H559DKZXTM9.jfif');

-- --------------------------------------------------------

--
-- Table structure for table `guide_tour_booking`
--

CREATE TABLE `guide_tour_booking` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `guide_id` bigint(20) UNSIGNED NOT NULL,
  `tour_booking_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `guide_tour_booking`
--

INSERT INTO `guide_tour_booking` (`id`, `created_at`, `updated_at`, `guide_id`, `tour_booking_id`) VALUES
(1, NULL, NULL, 1, 1),
(2, NULL, NULL, 1, 2),
(3, NULL, NULL, 1, 3);

-- --------------------------------------------------------

--
-- Table structure for table `language_guide`
--

CREATE TABLE `language_guide` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `spoken_language_id` bigint(20) UNSIGNED NOT NULL,
  `guide_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `language_guide`
--

INSERT INTO `language_guide` (`id`, `created_at`, `updated_at`, `spoken_language_id`, `guide_id`) VALUES
(1, NULL, NULL, 2, 1),
(2, NULL, NULL, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '2014_10_12_000000_create_users_table', 1),
(2, '2014_10_12_100000_create_password_reset_tokens_table', 1),
(3, '2019_08_19_000000_create_failed_jobs_table', 1),
(4, '2019_12_14_000001_create_personal_access_tokens_table', 1),
(5, '2024_08_14_160215_create_tours_table', 1),
(6, '2024_08_14_160321_create_guests_table', 1),
(7, '2024_08_14_161222_create_tour_bookings_table', 1),
(8, '2024_08_14_161254_create_drivers_table', 1),
(9, '2024_08_14_162507_create_cars_table', 1),
(10, '2024_08_18_130504_add_full_name_virt_col_to_guests_table', 1),
(11, '2024_08_20_170140_create_guides_table', 1),
(12, '2024_08_20_172829_add_group_number_to_tour_bookings_table', 1),
(13, '2024_08_20_173145_create_tour_payments_table', 1),
(14, '2024_08_21_182221_add_payment_date_to_tour_payments_table', 1),
(15, '2024_08_22_130240_create_supplier_payments_table', 1),
(16, '2024_08_26_044539_create_terminal_checks_table', 1),
(17, '2024_08_27_061603_add_doc_type_to_terminal_checks_table', 1),
(18, '2024_08_29_165243_create_car_driver_table', 1),
(19, '2024_08_29_171516_create_tour_tour_booking_table', 1),
(20, '2024_08_29_172300_create_driver_tour_booking_table', 1),
(21, '2024_08_29_173049_create_guest_tour_booking_table', 1),
(22, '2024_08_29_173420_create_guide_tour_booking_table', 1),
(23, '2024_08_29_175030_add_title_to_tours_table', 1),
(24, '2024_08_30_083732_create_ratings_table', 1),
(25, '2024_08_30_084455_add_driver_id_to_ratings_table', 1),
(26, '2024_08_30_092528_add_tour_booking_id_to_ratings_table', 1),
(27, '2024_08_30_093155_create_spoken_languages_table', 1),
(28, '2024_08_30_093828_create_language_guide_table', 1),
(29, '2024_08_31_175339_add_car_plate_col_to_car_driver_table', 1),
(30, '2024_09_02_175029_add_extra_details_address_city_cols_to_drivers_table', 1),
(31, '2024_09_02_180055_add_payment_type_to_supplier_payments_table', 1),
(32, '2024_09_02_180533_add_reciept_image_to_supplier_payments_table', 1),
(33, '2024_09_03_121715_add_status_to_tour_bookings_table', 1),
(34, '2024_09_03_122245_add_payment_status_to_tour_bookings_table', 1),
(35, '2024_09_05_042349_create_tour_booking_repeaters_table', 2);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ratings`
--

CREATE TABLE `ratings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `review_source` varchar(255) NOT NULL,
  `review_score` int(11) NOT NULL,
  `comments` text DEFAULT NULL,
  `driver_id` bigint(20) UNSIGNED NOT NULL,
  `tour_booking_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `spoken_languages`
--

CREATE TABLE `spoken_languages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `language` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `spoken_languages`
--

INSERT INTO `spoken_languages` (`id`, `created_at`, `updated_at`, `language`) VALUES
(1, '2024-09-04 03:13:59', '2024-09-04 03:13:59', 'Russian'),
(2, '2024-09-04 03:14:05', '2024-09-04 03:14:05', 'English');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tour_booking_id` bigint(20) UNSIGNED NOT NULL,
  `driver_id` bigint(20) UNSIGNED NOT NULL,
  `guide_id` bigint(20) UNSIGNED NOT NULL,
  `amount_paid` double NOT NULL,
  `payment_date` date NOT NULL,
  `payment_type` varchar(255) NOT NULL,
  `receipt_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `terminal_checks`
--

CREATE TABLE `terminal_checks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `check_date` date DEFAULT NULL,
  `amount` int(10) UNSIGNED DEFAULT NULL,
  `card_type` varchar(255) NOT NULL,
  `doc_type` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tours`
--

CREATE TABLE `tours` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tour_duration` varchar(255) NOT NULL,
  `tour_description` text NOT NULL,
  `title` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tours`
--

INSERT INTO `tours` (`id`, `created_at`, `updated_at`, `tour_duration`, `tour_description`, `title`) VALUES
(1, '2024-09-04 03:10:49', '2024-09-04 03:10:49', 'daytrip', 'dfd fdf', 'Shahrisabz');

-- --------------------------------------------------------

--
-- Table structure for table `tour_bookings`
--

CREATE TABLE `tour_bookings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `number_of_adults` int(11) NOT NULL,
  `number_of_children` int(11) DEFAULT NULL,
  `special_requests` text DEFAULT NULL,
  `pickup_location` varchar(255) DEFAULT NULL,
  `dropoff_location` varchar(255) DEFAULT NULL,
  `group_number` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `payment_status` varchar(255) DEFAULT NULL,
  `guest_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tour_bookings`
--

INSERT INTO `tour_bookings` (`id`, `created_at`, `updated_at`, `number_of_adults`, `number_of_children`, `special_requests`, `pickup_location`, `dropoff_location`, `group_number`, `status`, `payment_status`, `guest_id`) VALUES
(7, '2024-09-05 06:02:50', '2024-09-05 06:26:28', 2, 1, NULL, NULL, NULL, '675', NULL, NULL, 2);

-- --------------------------------------------------------

--
-- Table structure for table `tour_booking_repeaters`
--

CREATE TABLE `tour_booking_repeaters` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tour_id` bigint(20) UNSIGNED DEFAULT NULL,
  `driver_id` bigint(20) UNSIGNED DEFAULT NULL,
  `guide_id` bigint(20) UNSIGNED DEFAULT NULL,
  `payment_status` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  `special_requests` text DEFAULT NULL,
  `pickup_location` varchar(255) NOT NULL,
  `dropoff_location` varchar(255) NOT NULL,
  `tour_booking_id` bigint(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tour_booking_repeaters`
--

INSERT INTO `tour_booking_repeaters` (`id`, `created_at`, `updated_at`, `tour_id`, `driver_id`, `guide_id`, `payment_status`, `status`, `special_requests`, `pickup_location`, `dropoff_location`, `tour_booking_id`) VALUES
(1, '2024-09-05 06:02:50', '2024-09-05 06:02:50', 1, 2, 1, 'paid', 'in_progress', 'Non officiis delectu', 'Magni necessitatibus', 'Quisquam consequatur', 7);

-- --------------------------------------------------------

--
-- Table structure for table `tour_payments`
--

CREATE TABLE `tour_payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tour_booking_id` bigint(20) UNSIGNED NOT NULL,
  `amount_paid` double NOT NULL,
  `payment_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tour_tour_booking`
--

CREATE TABLE `tour_tour_booking` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tour_id` bigint(20) UNSIGNED NOT NULL,
  `tour_booking_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tour_tour_booking`
--

INSERT INTO `tour_tour_booking` (`id`, `created_at`, `updated_at`, `tour_id`, `tour_booking_id`) VALUES
(1, NULL, NULL, 1, 1),
(2, NULL, NULL, 1, 2),
(3, NULL, NULL, 1, 3);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `email_verified_at`, `password`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'Odil', 'odilorg@gmail.com', NULL, '$2y$12$VBp/oelGa8FE0Nm/Zq91iOPrwBLdYriJzc1aDxbusIBGWNqvfKbB2', 'HluaV4zJUhHUxXUMm8sgmR459KRyMRGJZjZCxgGPybEU7sRJtALJxkHfgPnP', '2024-09-04 00:40:10', '2024-09-04 00:40:10');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cars`
--
ALTER TABLE `cars`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `car_driver`
--
ALTER TABLE `car_driver`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `drivers`
--
ALTER TABLE `drivers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `driver_tour_booking`
--
ALTER TABLE `driver_tour_booking`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `guests`
--
ALTER TABLE `guests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `guest_tour_booking`
--
ALTER TABLE `guest_tour_booking`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `guides`
--
ALTER TABLE `guides`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `guide_tour_booking`
--
ALTER TABLE `guide_tour_booking`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `language_guide`
--
ALTER TABLE `language_guide`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `ratings`
--
ALTER TABLE `ratings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `spoken_languages`
--
ALTER TABLE `spoken_languages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `terminal_checks`
--
ALTER TABLE `terminal_checks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tours`
--
ALTER TABLE `tours`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tour_bookings`
--
ALTER TABLE `tour_bookings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tour_booking_repeaters`
--
ALTER TABLE `tour_booking_repeaters`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tour_payments`
--
ALTER TABLE `tour_payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tour_tour_booking`
--
ALTER TABLE `tour_tour_booking`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cars`
--
ALTER TABLE `cars`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `car_driver`
--
ALTER TABLE `car_driver`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `drivers`
--
ALTER TABLE `drivers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `driver_tour_booking`
--
ALTER TABLE `driver_tour_booking`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `guests`
--
ALTER TABLE `guests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `guest_tour_booking`
--
ALTER TABLE `guest_tour_booking`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `guides`
--
ALTER TABLE `guides`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `guide_tour_booking`
--
ALTER TABLE `guide_tour_booking`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `language_guide`
--
ALTER TABLE `language_guide`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ratings`
--
ALTER TABLE `ratings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `spoken_languages`
--
ALTER TABLE `spoken_languages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `terminal_checks`
--
ALTER TABLE `terminal_checks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tours`
--
ALTER TABLE `tours`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tour_bookings`
--
ALTER TABLE `tour_bookings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tour_booking_repeaters`
--
ALTER TABLE `tour_booking_repeaters`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tour_payments`
--
ALTER TABLE `tour_payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tour_tour_booking`
--
ALTER TABLE `tour_tour_booking`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
