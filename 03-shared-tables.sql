
-- ============================================================
-- 03-shared-tables.sql
--
-- The full shared table set for this platform (48 tables): plans,
-- subscriptions, tickets, reviews, faqs, other apps, GSC data tables,
-- admin tables, sessions - everything the panel reads and writes.
--
-- WpSite is NOT here - that one comes from 01-wp-sites.sql, which has
-- the platform-specific columns.
--
-- Run AFTER 01-wp-sites.sql (and before 02 if starting fresh -
-- 02 only touches WpSite, so order with 02 does not matter).
-- Safe on an empty database; existing tables are not dropped, so on
-- a database where some tables already exist the CREATE will error
-- for just those - check the message and skip them.
-- ============================================================
-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: ecwid-googlesearchconsole
-- ------------------------------------------------------
-- Server version	8.0.46-0ubuntu0.22.04.3

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `Country`
--

DROP TABLE IF EXISTS `Country`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Country` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `createdAt` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `Country_name_key` (`name`),
  UNIQUE KEY `Country_code_key` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `Country`
--

/*!40000 ALTER TABLE `Country` DISABLE KEYS */;
/*!40000 ALTER TABLE `Country` ENABLE KEYS */;

--
-- Table structure for table `EcwidSite`
--

DROP TABLE IF EXISTS `EcwidSite`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `EcwidSite`
--


--
-- Table structure for table `EcwidSiteProfile`
--

DROP TABLE IF EXISTS `EcwidSiteProfile`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `EcwidSiteProfile` (
  `id` int NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FK reference to ShopifySite.instance_id',
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_type` varchar(55) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `language` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(320) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `social_links_json` json DEFAULT NULL COMMENT 'facebook, instagram, twitter, linkedin, etc',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_instance_id` (`instance_id`),
  CONSTRAINT `fk_shopify_profile_instance` FOREIGN KEY (`instance_id`) REFERENCES `EcwidSite` (`instance_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `EcwidSiteProfile`
--

/*!40000 ALTER TABLE `EcwidSiteProfile` DISABLE KEYS */;
/*!40000 ALTER TABLE `EcwidSiteProfile` ENABLE KEYS */;

--
-- Table structure for table `admin_settings`
--

DROP TABLE IF EXISTS `admin_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `key_name` varchar(255) NOT NULL,
  `value` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_name` (`key_name`),
  UNIQUE KEY `uniq_key_name` (`key_name`)
) ENGINE=InnoDB AUTO_INCREMENT=138 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_settings`
--

/*!40000 ALTER TABLE `admin_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_settings` ENABLE KEYS */;

--
-- Table structure for table `admin_users`
--

DROP TABLE IF EXISTS `admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'password_hash() value',
  `role` enum('superadmin','admin','viewer') DEFAULT 'admin',
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_users`
--

/*!40000 ALTER TABLE `admin_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `admin_users` ENABLE KEYS */;

--
-- Table structure for table `app_free_trials`
--

DROP TABLE IF EXISTS `app_free_trials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_free_trials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(255) NOT NULL,
  `status` enum('active','expired','converted') NOT NULL DEFAULT 'active',
  `started_at` datetime(3) NOT NULL,
  `expires_on` datetime(3) NOT NULL,
  `cancelled_at` datetime(3) DEFAULT NULL,
  `converted_at` datetime(3) DEFAULT NULL,
  `raw_event` json DEFAULT NULL,
  `created_at` datetime(3) NOT NULL,
  `updated_at` datetime(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_free_trial_instance` (`instance_id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_free_trials`
--

/*!40000 ALTER TABLE `app_free_trials` DISABLE KEYS */;
/*!40000 ALTER TABLE `app_free_trials` ENABLE KEYS */;

--
-- Table structure for table `app_reviews`
--

DROP TABLE IF EXISTS `app_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_reviews` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` tinyint unsigned NOT NULL COMMENT '1-10 rating score',
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_instance_id` (`instance_id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_reviews`
--

/*!40000 ALTER TABLE `app_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `app_reviews` ENABLE KEYS */;

--
-- Table structure for table `app_subscriptions`
--

DROP TABLE IF EXISTS `app_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(200) NOT NULL,
  `plan_name` varchar(100) NOT NULL,
  `billing_period` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `started_at` datetime NOT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `expires_on` datetime DEFAULT NULL,
  `raw_event` text,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_synced_at` datetime DEFAULT NULL COMMENT 'Last successful sync time with lead',
  `payment_method` varchar(50) DEFAULT NULL,
  `stripe_subscription_id` varchar(255) DEFAULT NULL,
  `cancel_reason` text,
  `duration` varchar(50) DEFAULT NULL,
  `currency` varchar(10) DEFAULT 'USD',
  `price` decimal(10,2) DEFAULT NULL,
  `customer_id` varchar(155) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instance_id` (`instance_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_subscriptions`
--

/*!40000 ALTER TABLE `app_subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `app_subscriptions` ENABLE KEYS */;

--
-- Table structure for table `cancellation_sessions`
--

DROP TABLE IF EXISTS `cancellation_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cancellation_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_step` int DEFAULT NULL,
  `completed` tinyint(1) DEFAULT '0',
  `final_action` varchar(100) DEFAULT NULL,
  `join_reason` varchar(255) DEFAULT NULL,
  `join_extra` text,
  `mirror_screen_shown` tinyint(1) DEFAULT '0',
  `leave_reason` varchar(255) DEFAULT NULL,
  `leave_extra` text,
  `offer_type` varchar(255) DEFAULT NULL,
  `offer_selected` varchar(255) DEFAULT NULL,
  `call_scheduled` tinyint(1) DEFAULT '0',
  `review_shown` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instance_id` (`instance_id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cancellation_sessions`
--

/*!40000 ALTER TABLE `cancellation_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `cancellation_sessions` ENABLE KEYS */;

--
-- Table structure for table `documentation`
--

DROP TABLE IF EXISTS `documentation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `slug` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `documentation_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `documentation_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documentation`
--

/*!40000 ALTER TABLE `documentation` DISABLE KEYS */;
/*!40000 ALTER TABLE `documentation` ENABLE KEYS */;

--
-- Table structure for table `documentation_categories`
--

DROP TABLE IF EXISTS `documentation_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documentation_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documentation_categories`
--

/*!40000 ALTER TABLE `documentation_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `documentation_categories` ENABLE KEYS */;

--
-- Table structure for table `ecwid_settings`
--

DROP TABLE IF EXISTS `ecwid_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ecwid_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` varchar(255) NOT NULL,
  `client_secret` text NOT NULL,
  `redirect_uri` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ecwid_settings`
--

/*!40000 ALTER TABLE `ecwid_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `ecwid_settings` ENABLE KEYS */;

--
-- Table structure for table `email_categories`
--

DROP TABLE IF EXISTS `email_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_categories`
--

/*!40000 ALTER TABLE `email_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_categories` ENABLE KEYS */;

--
-- Table structure for table `email_templates`
--

DROP TABLE IF EXISTS `email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `category_id` int NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `email_templates_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `email_categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_templates`
--

/*!40000 ALTER TABLE `email_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_templates` ENABLE KEYS */;

--
-- Table structure for table `encharge_email_logs`
--

DROP TABLE IF EXISTS `encharge_email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `encharge_email_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `website_id` int unsigned NOT NULL DEFAULT '0',
  `encharge_id` varchar(255) NOT NULL,
  `flow_name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_website_id` (`website_id`),
  KEY `idx_encharge_id` (`encharge_id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `encharge_email_logs`
--

/*!40000 ALTER TABLE `encharge_email_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `encharge_email_logs` ENABLE KEYS */;

--
-- Table structure for table `faq_categories`
--

DROP TABLE IF EXISTS `faq_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `faq_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID for each FAQ category.',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Category name (e.g., Billing & Plans, Troubleshooting).',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when the category was created.',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp of the last update.',
  `sort_order` int unsigned NOT NULL DEFAULT '0' COMMENT 'Display order for categories',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `faq_categories`
--

/*!40000 ALTER TABLE `faq_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `faq_categories` ENABLE KEYS */;

--
-- Table structure for table `faqs`
--

DROP TABLE IF EXISTS `faqs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `faqs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID for each FAQ entry.',
  `question` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The question text of the FAQ.',
  `answer` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'The detailed answer to the question.',
  `sort_order` int unsigned NOT NULL DEFAULT '0' COMMENT 'The order in which the FAQ should appear on the page.',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when the FAQ was created.',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Timestamp of the last update.',
  `category_id` int unsigned NOT NULL COMMENT 'FK to faq_categories.id',
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `faqs`
--

/*!40000 ALTER TABLE `faqs` DISABLE KEYS */;
/*!40000 ALTER TABLE `faqs` ENABLE KEYS */;

--
-- Table structure for table `gbigcomm_settings`
--

DROP TABLE IF EXISTS `gbigcomm_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gbigcomm_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` varchar(255) NOT NULL,
  `client_secret` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gbigcomm_settings`
--

/*!40000 ALTER TABLE `gbigcomm_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `gbigcomm_settings` ENABLE KEYS */;

--
-- Table structure for table `google_accounts`
--

DROP TABLE IF EXISTS `google_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text,
  `connected` tinyint(1) NOT NULL DEFAULT '1',
  `last_error` text,
  `token_expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `scope` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_instance` (`instance_id`),
  KEY `idx_instance` (`instance_id`)
) ENGINE=InnoDB AUTO_INCREMENT=436 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `google_accounts`
--

/*!40000 ALTER TABLE `google_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `google_accounts` ENABLE KEYS */;

--
-- Table structure for table `google_settings`
--

DROP TABLE IF EXISTS `google_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `google_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` text NOT NULL,
  `client_secret` text NOT NULL,
  `redirect_uri` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `google_settings`
--

/*!40000 ALTER TABLE `google_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `google_settings` ENABLE KEYS */;

--
-- Table structure for table `gsc_backfill_log`
--

DROP TABLE IF EXISTS `gsc_backfill_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gsc_backfill_log` (
  `instance_id` varchar(191) NOT NULL,
  `status` varchar(20) NOT NULL,
  `rows_written` int unsigned NOT NULL DEFAULT '0',
  `took_seconds` decimal(7,1) DEFAULT NULL,
  `message` text,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`instance_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gsc_backfill_log`
--

/*!40000 ALTER TABLE `gsc_backfill_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `gsc_backfill_log` ENABLE KEYS */;

--
-- Table structure for table `gsc_ctr_curve`
--

DROP TABLE IF EXISTS `gsc_ctr_curve`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gsc_ctr_curve` (
  `instance_id` varchar(191) NOT NULL,
  `position_band` tinyint unsigned NOT NULL COMMENT '1..20',
  `expected_ctr` decimal(6,4) NOT NULL COMMENT '0.0450 = 4.5%',
  `sample_size` int unsigned NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`instance_id`,`position_band`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gsc_ctr_curve`
--

/*!40000 ALTER TABLE `gsc_ctr_curve` DISABLE KEYS */;
/*!40000 ALTER TABLE `gsc_ctr_curve` ENABLE KEYS */;

--
-- Table structure for table `gsc_domain_verifications`
--

DROP TABLE IF EXISTS `gsc_domain_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gsc_domain_verifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) NOT NULL,
  `site_url` varchar(2048) NOT NULL,
  `brand_name` varchar(255) DEFAULT NULL,
  `meta_token` varchar(255) DEFAULT NULL,
  `meta_tag` text,
  `verification_status` enum('pending','verified','failed') DEFAULT 'pending',
  `verification_method` varchar(50) DEFAULT NULL,
  `verification_checked_at` datetime DEFAULT NULL,
  `verification_verified_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_instance` (`instance_id`),
  KEY `idx_instance` (`instance_id`)
) ENGINE=InnoDB AUTO_INCREMENT=685 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gsc_domain_verifications`
--

/*!40000 ALTER TABLE `gsc_domain_verifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `gsc_domain_verifications` ENABLE KEYS */;

--
-- Table structure for table `gsc_page_query_daily`
--

DROP TABLE IF EXISTS `gsc_page_query_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gsc_page_query_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) NOT NULL,
  `date` date NOT NULL,
  `page_hash` char(32) NOT NULL,
  `query_hash` char(32) NOT NULL,
  `page` varchar(1000) NOT NULL,
  `query` varchar(500) NOT NULL,
  `clicks` int unsigned NOT NULL DEFAULT '0',
  `impressions` int unsigned NOT NULL DEFAULT '0',
  `position` decimal(6,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`,`date`),
  UNIQUE KEY `uniq_row` (`instance_id`,`date`,`page_hash`,`query_hash`),
  KEY `idx_inst_date` (`instance_id`,`date`),
  KEY `idx_inst_page` (`instance_id`,`page_hash`,`date`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
/*!50100 PARTITION BY RANGE (to_days(`date`))
(PARTITION p202608 VALUES LESS THAN (740225) ENGINE = InnoDB,
 PARTITION p202609 VALUES LESS THAN (740255) ENGINE = InnoDB,
 PARTITION p202610 VALUES LESS THAN (740286) ENGINE = InnoDB,
 PARTITION p202611 VALUES LESS THAN (740316) ENGINE = InnoDB,
 PARTITION p202612 VALUES LESS THAN (740347) ENGINE = InnoDB,
 PARTITION p202701 VALUES LESS THAN (740378) ENGINE = InnoDB,
 PARTITION p202702 VALUES LESS THAN (740406) ENGINE = InnoDB,
 PARTITION p202703 VALUES LESS THAN (740437) ENGINE = InnoDB,
 PARTITION p202704 VALUES LESS THAN (740467) ENGINE = InnoDB,
 PARTITION p202705 VALUES LESS THAN (740498) ENGINE = InnoDB,
 PARTITION p202706 VALUES LESS THAN (740528) ENGINE = InnoDB,
 PARTITION p202707 VALUES LESS THAN (740559) ENGINE = InnoDB,
 PARTITION pmax VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gsc_page_query_daily`
--

/*!40000 ALTER TABLE `gsc_page_query_daily` DISABLE KEYS */;
/*!40000 ALTER TABLE `gsc_page_query_daily` ENABLE KEYS */;

--
-- Table structure for table `gsc_query_daily`
--

DROP TABLE IF EXISTS `gsc_query_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gsc_query_daily` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) NOT NULL,
  `date` date NOT NULL,
  `query_hash` char(32) NOT NULL COMMENT 'md5(query) — keeps the unique key short',
  `query` varchar(500) NOT NULL,
  `clicks` int unsigned NOT NULL DEFAULT '0',
  `impressions` int unsigned NOT NULL DEFAULT '0',
  `position` decimal(6,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_row` (`instance_id`,`date`,`query_hash`),
  KEY `idx_inst_date` (`instance_id`,`date`),
  KEY `idx_inst_q` (`instance_id`,`query_hash`,`date`)
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gsc_query_daily`
--

/*!40000 ALTER TABLE `gsc_query_daily` DISABLE KEYS */;
/*!40000 ALTER TABLE `gsc_query_daily` ENABLE KEYS */;

--
-- Table structure for table `gsc_snapshot_runs`
--

DROP TABLE IF EXISTS `gsc_snapshot_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gsc_snapshot_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) NOT NULL,
  `date` date NOT NULL,
  `status` enum('running','done','failed') NOT NULL DEFAULT 'running',
  `query_rows` int unsigned DEFAULT '0',
  `pq_rows` int unsigned DEFAULT '0',
  `error` text,
  `started_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_run` (`instance_id`,`date`),
  KEY `idx_status` (`status`,`date`)
) ENGINE=InnoDB AUTO_INCREMENT=723 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gsc_snapshot_runs`
--

/*!40000 ALTER TABLE `gsc_snapshot_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `gsc_snapshot_runs` ENABLE KEYS */;

--
-- Table structure for table `insight_events`
--

DROP TABLE IF EXISTS `insight_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `insight_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `insight_id` bigint unsigned NOT NULL,
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_insight` (`insight_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `insight_events`
--

/*!40000 ALTER TABLE `insight_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `insight_events` ENABLE KEYS */;

--
-- Table structure for table `insights`
--

DROP TABLE IF EXISTS `insights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `insights` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) NOT NULL,
  `type` enum('ctr','drop','newkw','broken','redirect','indexing') NOT NULL,
  `dedup_key` varchar(255) NOT NULL COMMENT 'type + entity, e.g. ctr:/best-shoes',
  `entity` varchar(1000) NOT NULL COMMENT 'page URL or query text',
  `title` varchar(255) NOT NULL,
  `severity` enum('critical','high','medium','low') NOT NULL,
  `impact_clicks` int NOT NULL COMMENT 'signed: + = gain, - = loss, per month',
  `what_json` json NOT NULL COMMENT '[["Average position","4.2"], ...]',
  `why_text` text NOT NULL,
  `rec_text` text NOT NULL,
  `action_json` json DEFAULT NULL COMMENT '{"primary":{"label":"","view":""},"secondary":null}',
  `status` enum('new','read','actioned','dismissed','resolved') NOT NULL DEFAULT 'new',
  `open_flag` tinyint GENERATED ALWAYS AS ((case when (`status` in (_utf8mb4'new',_utf8mb4'read',_utf8mb4'actioned')) then 1 else NULL end)) STORED,
  `occurrences` int unsigned NOT NULL DEFAULT '1',
  `first_seen` date NOT NULL,
  `last_seen` date NOT NULL,
  `suppress_until` date DEFAULT NULL COMMENT 'set when dismissed',
  `resolved_note` varchar(500) DEFAULT NULL,
  `draft_json` json DEFAULT NULL COMMENT 'user-written title/description draft, not yet published',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_open` (`instance_id`,`dedup_key`,`open_flag`),
  KEY `idx_feed` (`instance_id`,`status`,`severity`),
  KEY `idx_unread` (`instance_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `insights`
--

/*!40000 ALTER TABLE `insights` DISABLE KEYS */;
/*!40000 ALTER TABLE `insights` ENABLE KEYS */;

--
-- Table structure for table `other_apps`
--

DROP TABLE IF EXISTS `other_apps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `other_apps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_name` char(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_index` enum('enable','disable') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'disable',
  `app_price` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `title` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tag_line` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `subtitle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `button_text` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Visit on App store',
  `button_link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `sort_order` int unsigned DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `other_apps`
--

/*!40000 ALTER TABLE `other_apps` DISABLE KEYS */;
/*!40000 ALTER TABLE `other_apps` ENABLE KEYS */;

--
-- Table structure for table `otp_verifications`
--

DROP TABLE IF EXISTS `otp_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `otp_verifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `otp` varchar(6) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `instance_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_email_otp` (`email`,`otp`)
) ENGINE=InnoDB AUTO_INCREMENT=169 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_verifications`
--

/*!40000 ALTER TABLE `otp_verifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_verifications` ENABLE KEYS */;

--
-- Table structure for table `page_status_cache`
--

DROP TABLE IF EXISTS `page_status_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `page_status_cache` (
  `url_hash` char(32) NOT NULL,
  `url` varchar(1000) NOT NULL,
  `http_code` smallint NOT NULL DEFAULT '0',
  `status` enum('ok','redirect','dead','error','unknown') NOT NULL DEFAULT 'unknown',
  `checked_at` datetime NOT NULL,
  PRIMARY KEY (`url_hash`),
  KEY `idx_status` (`status`),
  KEY `idx_checked_at` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `page_status_cache`
--

/*!40000 ALTER TABLE `page_status_cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `page_status_cache` ENABLE KEYS */;

--
-- Table structure for table `plan_feature_map`
--

DROP TABLE IF EXISTS `plan_feature_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_feature_map` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plan_id` int NOT NULL,
  `feature_id` int NOT NULL,
  `included` tinyint(1) NOT NULL DEFAULT '1',
  `limit_value` int DEFAULT NULL,
  `unlimited` tinyint(1) NOT NULL DEFAULT '0',
  `meta` json DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_feature` (`plan_id`,`feature_id`),
  KEY `feature_id` (`feature_id`)
) ENGINE=InnoDB AUTO_INCREMENT=152 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plan_feature_map`
--

/*!40000 ALTER TABLE `plan_feature_map` DISABLE KEYS */;
/*!40000 ALTER TABLE `plan_feature_map` ENABLE KEYS */;

--
-- Table structure for table `plan_feature_text`
--

DROP TABLE IF EXISTS `plan_feature_text`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_feature_text` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plan_id` int NOT NULL,
  `feature_id` int NOT NULL,
  `display_text` varchar(500) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_feature_text` (`plan_id`,`feature_id`),
  KEY `feature_id` (`feature_id`)
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plan_feature_text`
--

/*!40000 ALTER TABLE `plan_feature_text` DISABLE KEYS */;
/*!40000 ALTER TABLE `plan_feature_text` ENABLE KEYS */;

--
-- Table structure for table `plan_features`
--

DROP TABLE IF EXISTS `plan_features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_features` (
  `id` int NOT NULL AUTO_INCREMENT,
  `featureName` varchar(255) NOT NULL,
  `tooltip_text` text,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plan_features`
--

/*!40000 ALTER TABLE `plan_features` DISABLE KEYS */;
/*!40000 ALTER TABLE `plan_features` ENABLE KEYS */;

--
-- Table structure for table `pricing_plans`
--

DROP TABLE IF EXISTS `pricing_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `isRecurring` tinyint(1) NOT NULL,
  `billingPeriod` varchar(20) NOT NULL,
  `planProductId` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `showInMarket` tinyint(1) DEFAULT '1',
  `createdAt` datetime DEFAULT CURRENT_TIMESTAMP,
  `lookup_key` varchar(255) DEFAULT NULL,
  `price_id` varchar(255) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `updatedAt` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_plans`
--

/*!40000 ALTER TABLE `pricing_plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_plans` ENABLE KEYS */;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;

--
-- Table structure for table `setup_wizard_status`
--

DROP TABLE IF EXISTS `setup_wizard_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `setup_wizard_status` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(255) NOT NULL,
  `step1` tinyint(1) DEFAULT '0',
  `step2` tinyint(1) DEFAULT '0',
  `step3` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instance_id` (`instance_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2248 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setup_wizard_status`
--

/*!40000 ALTER TABLE `setup_wizard_status` DISABLE KEYS */;
/*!40000 ALTER TABLE `setup_wizard_status` ENABLE KEYS */;

--
-- Table structure for table `shopify_credentials`
--

DROP TABLE IF EXISTS `shopify_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shopify_credentials` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `key_name` varchar(255) NOT NULL,
  `value` text NOT NULL,
  `encrypted` tinyint(1) DEFAULT '0',
  `type` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_name` (`key_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shopify_credentials`
--

/*!40000 ALTER TABLE `shopify_credentials` DISABLE KEYS */;
/*!40000 ALTER TABLE `shopify_credentials` ENABLE KEYS */;

--
-- Table structure for table `sitemap_submission_logs`
--

DROP TABLE IF EXISTS `sitemap_submission_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sitemap_submission_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) NOT NULL,
  `sitemap_url` varchar(2048) NOT NULL,
  `status` enum('Success','Failed') NOT NULL,
  `http_code` int DEFAULT NULL,
  `google_response` text,
  `submitted_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_instance` (`instance_id`)
) ENGINE=InnoDB AUTO_INCREMENT=584 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sitemap_submission_logs`
--

/*!40000 ALTER TABLE `sitemap_submission_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `sitemap_submission_logs` ENABLE KEYS */;

--
-- Table structure for table `sitemaps`
--

DROP TABLE IF EXISTS `sitemaps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sitemaps` (
  `id` int NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) NOT NULL,
  `domain` varchar(512) DEFAULT NULL,
  `sitemap_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Pending','Success','Failed','Error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Pending',
  `last_submitted_at` datetime DEFAULT NULL,
  `last_downloaded` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submission_count` int unsigned NOT NULL DEFAULT '0',
  `last_status_message` text,
  `is_index` tinyint(1) NOT NULL DEFAULT '0',
  `warnings` int NOT NULL DEFAULT '0',
  `errors` int NOT NULL DEFAULT '0',
  `discovered_pages` int unsigned DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_instance_sitemap` (`instance_id`,`sitemap_url`)
) ENGINE=InnoDB AUTO_INCREMENT=206 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sitemaps`
--

/*!40000 ALTER TABLE `sitemaps` DISABLE KEYS */;
/*!40000 ALTER TABLE `sitemaps` ENABLE KEYS */;

--
-- Table structure for table `sitemaps_duplicates_backup`
--

DROP TABLE IF EXISTS `sitemaps_duplicates_backup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sitemaps_duplicates_backup` (
  `id` int NOT NULL DEFAULT '0',
  `instance_id` varchar(191) NOT NULL,
  `domain` varchar(512) DEFAULT NULL,
  `sitemap_url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('Pending','Success','Failed','Error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Pending',
  `last_submitted_at` datetime DEFAULT NULL,
  `last_downloaded` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `submission_count` int unsigned NOT NULL DEFAULT '0',
  `last_status_message` text,
  `is_index` tinyint(1) NOT NULL DEFAULT '0',
  `warnings` int NOT NULL DEFAULT '0',
  `errors` int NOT NULL DEFAULT '0',
  `discovered_pages` int unsigned DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sitemaps_duplicates_backup`
--

/*!40000 ALTER TABLE `sitemaps_duplicates_backup` DISABLE KEYS */;
/*!40000 ALTER TABLE `sitemaps_duplicates_backup` ENABLE KEYS */;

--
-- Table structure for table `stripe_refund`
--

DROP TABLE IF EXISTS `stripe_refund`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stripe_refund` (
  `id` int NOT NULL,
  `subscription_id` int NOT NULL,
  `stripe_customer_id` varchar(255) NOT NULL,
  `stripe_refund_id` varchar(255) NOT NULL,
  `refund_amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'USD',
  `status` varchar(50) DEFAULT NULL,
  `refund_reason` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stripe_refund_id` (`stripe_refund_id`),
  KEY `fk_subscription` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stripe_refund`
--

/*!40000 ALTER TABLE `stripe_refund` DISABLE KEYS */;
/*!40000 ALTER TABLE `stripe_refund` ENABLE KEYS */;

--
-- Table structure for table `subscription_logs`
--

DROP TABLE IF EXISTS `subscription_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `subscription_id` int NOT NULL,
  `instance_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_log_subscription` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_logs`
--

/*!40000 ALTER TABLE `subscription_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscription_logs` ENABLE KEYS */;

--
-- Table structure for table `ticket_attachments`
--

DROP TABLE IF EXISTS `ticket_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint unsigned NOT NULL,
  `ticket_id` bigint unsigned NOT NULL,
  `instance_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_instance` (`message_id`,`instance_id`),
  KEY `idx_ticket_instance` (`ticket_id`,`instance_id`),
  CONSTRAINT `fk_ta_message_min` FOREIGN KEY (`message_id`) REFERENCES `ticket_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_attachments`
--

/*!40000 ALTER TABLE `ticket_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_attachments` ENABLE KEYS */;

--
-- Table structure for table `ticket_messages`
--

DROP TABLE IF EXISTS `ticket_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint unsigned NOT NULL,
  `instance_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT '0',
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket_instance` (`ticket_id`,`instance_id`),
  CONSTRAINT `fk_tm_ticket_min2` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_messages`
--

/*!40000 ALTER TABLE `ticket_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_messages` ENABLE KEYS */;

--
-- Table structure for table `ticket_status_history`
--

DROP TABLE IF EXISTS `ticket_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_status_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint unsigned NOT NULL,
  `instance_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_history_ticket` (`ticket_id`,`instance_id`),
  CONSTRAINT `fk_status_ticket_min2` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=119 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_status_history`
--

/*!40000 ALTER TABLE `ticket_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_status_history` ENABLE KEYS */;

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ticket_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('open','pending','closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `priority` enum('low','normal','high','urgent') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `last_message_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_instance_ticket_number` (`instance_id`,`ticket_number`),
  KEY `idx_instance_status` (`instance_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `tickets` ENABLE KEYS */;

--
-- Table structure for table `webhook_events`
--

DROP TABLE IF EXISTS `webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `webhook_id` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `webhook_id` (`webhook_id`)
) ENGINE=InnoDB AUTO_INCREMENT=354 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `webhook_events`
--

/*!40000 ALTER TABLE `webhook_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `webhook_events` ENABLE KEYS */;

--
-- Table structure for table `webhook_test_logs`
--

DROP TABLE IF EXISTS `webhook_test_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_test_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `raw_data` longtext,
  `received_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `stage` varchar(50) DEFAULT NULL,
  `event_type` varchar(100) DEFAULT NULL,
  `error_message` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=223 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `webhook_test_logs`
--

/*!40000 ALTER TABLE `webhook_test_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `webhook_test_logs` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-14  9:19:28
