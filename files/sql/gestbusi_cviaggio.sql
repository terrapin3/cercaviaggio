-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Creato il: Mar 31, 2026 alle 03:34
-- Versione del server: 5.7.44-48
-- Versione PHP: 8.2.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gestbusi_cviaggio`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `abbcarn_lista`
--

CREATE TABLE `abbcarn_lista` (
  `id_codabbcarn_l` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `nome` varchar(200) DEFAULT '-',
  `codice` varchar(20) NOT NULL,
  `prezzo` decimal(10,2) NOT NULL DEFAULT '0.00',
  `giorni_sett` varchar(20) DEFAULT '-',
  `durata_gg` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `linee` varchar(150) NOT NULL,
  `id_corsa` varchar(150) DEFAULT '0',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `abbcarn_utenti`
--

CREATE TABLE `abbcarn_utenti` (
  `id_codabbcarn_u` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_codabbcarn_l` bigint(20) UNSIGNED NOT NULL,
  `id_vg` bigint(20) UNSIGNED NOT NULL,
  `codice_ac` varchar(20) NOT NULL,
  `codice_u` varchar(20) NOT NULL,
  `transaction_id` varchar(50) DEFAULT '0',
  `pagato` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `acquistato` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_ini` date DEFAULT NULL,
  `data_fin` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `abbcarn_utenti_reg`
--

CREATE TABLE `abbcarn_utenti_reg` (
  `id_abbcarn_reg` bigint(20) UNSIGNED NOT NULL,
  `id_codabbcarn_u` bigint(20) UNSIGNED NOT NULL,
  `operazione` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `snapshot_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `aziende`
--

CREATE TABLE `aziende` (
  `id_az` int(10) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `pi` varchar(32) DEFAULT '-',
  `cf` varchar(32) DEFAULT '-',
  `ind` varchar(255) DEFAULT '-',
  `tel` varchar(30) DEFAULT '-',
  `recapiti` varchar(255) DEFAULT '-',
  `email_pg` varchar(150) DEFAULT '-',
  `auth_tk` varchar(255) DEFAULT '-',
  `prof` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `timezone` varchar(50) NOT NULL DEFAULT 'Europe/Rome',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `aziende_comm`
--

CREATE TABLE `aziende_comm` (
  `id_azcomm` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `comm_app` decimal(5,2) NOT NULL DEFAULT '0.00',
  `comm_web` decimal(5,2) NOT NULL DEFAULT '0.00',
  `comm_search` decimal(5,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `aziende_pag2`
--

CREATE TABLE `aziende_pag2` (
  `az_p` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `environment` enum('production','sandbox') NOT NULL,
  `email_pg` varchar(150) DEFAULT '-',
  `auth_tk` varchar(255) DEFAULT '-',
  `ppredirect` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `ppcheckout` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `ppcarta` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `ut_api` varchar(150) DEFAULT '-',
  `pass_api` varchar(150) DEFAULT '-',
  `firma_api` varchar(255) DEFAULT '-',
  `accountid` varchar(255) DEFAULT '-',
  `clientid` varchar(255) DEFAULT '-',
  `secret` varchar(255) DEFAULT '-',
  `pk` varchar(255) DEFAULT '-',
  `sk` varchar(255) DEFAULT '-',
  `wh` varchar(255) DEFAULT '-',
  `tipo` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `richiesta` datetime DEFAULT NULL,
  `token` varchar(255) DEFAULT NULL,
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `stato_app` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `biglietti`
--

CREATE TABLE `biglietti` (
  `id_bg` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_ut` bigint(20) UNSIGNED DEFAULT '0',
  `id_mz` bigint(20) UNSIGNED DEFAULT '0',
  `id_linea` int(10) UNSIGNED DEFAULT '0',
  `id_corsa` int(10) UNSIGNED DEFAULT '0',
  `id_r` bigint(20) UNSIGNED DEFAULT '0',
  `id_sott1` int(10) UNSIGNED NOT NULL,
  `id_sott2` int(10) UNSIGNED NOT NULL,
  `prezzo` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pen` decimal(10,2) NOT NULL DEFAULT '0.00',
  `camb` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `sospeso` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `rid` tinyint(3) UNSIGNED NOT NULL,
  `pacco` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `pacco_a` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `prz_pacco` decimal(10,2) NOT NULL DEFAULT '0.00',
  `prz_pacco_a` decimal(10,2) NOT NULL DEFAULT '0.00',
  `prenotaz` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `codice` varchar(20) NOT NULL DEFAULT '0',
  `codice_camb` varchar(20) NOT NULL DEFAULT '0',
  `transaction_id` varchar(150) DEFAULT '0',
  `posto` smallint(5) UNSIGNED NOT NULL DEFAULT '0',
  `prz_posto` decimal(10,2) NOT NULL DEFAULT '0.00',
  `prz_comm` decimal(10,2) NOT NULL DEFAULT '0.00',
  `note` varchar(255) DEFAULT ' ',
  `pos` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `id_vg` bigint(20) UNSIGNED DEFAULT '0',
  `id_vgt` bigint(20) UNSIGNED DEFAULT '0',
  `id_cod` bigint(20) UNSIGNED DEFAULT '0',
  `id_codabbcarn_u` bigint(20) UNSIGNED DEFAULT '0',
  `stato` tinyint(3) UNSIGNED NOT NULL,
  `rimborsato` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `controllato` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `pagato` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `stampato` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `mz_dt` bigint(20) UNSIGNED DEFAULT '0',
  `type` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `app` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `txn_id` varchar(100) NOT NULL DEFAULT '0',
  `attesa` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `data` datetime DEFAULT NULL,
  `data2` datetime DEFAULT NULL,
  `acquistato` datetime NOT NULL,
  `data_sos` datetime DEFAULT '1970-01-01 00:00:00',
  `data_attesa` datetime DEFAULT '1970-01-01 00:00:00',
  `visto` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `biglietti_log`
--

CREATE TABLE `biglietti_log` (
  `id_b_log` bigint(20) UNSIGNED NOT NULL,
  `id_bg` bigint(20) UNSIGNED NOT NULL,
  `id_utop` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `operazione` varchar(100) NOT NULL DEFAULT '-',
  `payload` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `biglietti_reg`
--

CREATE TABLE `biglietti_reg` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_bg` bigint(20) UNSIGNED NOT NULL,
  `operazione` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `snapshot_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `corse`
--

CREATE TABLE `corse` (
  `id_corsa` int(10) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_linea` int(10) UNSIGNED NOT NULL,
  `nome` varchar(200) NOT NULL,
  `tempo_acquisto` smallint(5) UNSIGNED NOT NULL DEFAULT '30',
  `gruppo` varchar(16) NOT NULL DEFAULT 'a_1',
  `recapiti` varchar(50) DEFAULT '-',
  `transitoria` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `visualizzato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `direction_id` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `corse_fermate`
--

CREATE TABLE `corse_fermate` (
  `id_corse_f` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_corsa` int(10) UNSIGNED NOT NULL,
  `id_sott` int(10) UNSIGNED NOT NULL,
  `orario` time NOT NULL DEFAULT '00:00:00',
  `ordine` smallint(5) UNSIGNED NOT NULL DEFAULT '0',
  `giornoDopo` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `giornoDopo1` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `giornoDopo2` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `giornoDopo3` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `distance` decimal(10,3) DEFAULT NULL,
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `gtfs` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `gtfs2` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `gtfs3` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `lat` decimal(10,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_api_logs`
--

CREATE TABLE `cv_api_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `direction` enum('outbound','inbound') NOT NULL,
  `id_az` int(10) UNSIGNED DEFAULT NULL,
  `endpoint` varchar(120) NOT NULL,
  `http_method` varchar(10) NOT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `status_code` smallint(5) UNSIGNED DEFAULT NULL,
  `latency_ms` int(10) UNSIGNED DEFAULT NULL,
  `request_payload` longtext,
  `response_payload` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_assistant_conversations`
--

CREATE TABLE `cv_assistant_conversations` (
  `id_conversation` bigint(20) UNSIGNED NOT NULL,
  `session_key` varchar(80) NOT NULL,
  `channel` varchar(32) NOT NULL DEFAULT 'web',
  `ticket_code` varchar(80) NOT NULL DEFAULT '',
  `provider_code` varchar(50) NOT NULL DEFAULT '',
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `messages_count` int(11) NOT NULL DEFAULT '0',
  `context_json` mediumtext,
  `client_ip_hash` char(64) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_assistant_feedback`
--

CREATE TABLE `cv_assistant_feedback` (
  `id_feedback` bigint(20) UNSIGNED NOT NULL,
  `id_message` bigint(20) UNSIGNED NOT NULL,
  `session_key` varchar(80) NOT NULL,
  `feedback` tinyint(1) NOT NULL DEFAULT '0',
  `meta_json` mediumtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_assistant_knowledge`
--

CREATE TABLE `cv_assistant_knowledge` (
  `id_knowledge` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(190) NOT NULL,
  `question_example` varchar(255) NOT NULL DEFAULT '',
  `keywords` varchar(255) NOT NULL DEFAULT '',
  `answer_text` mediumtext NOT NULL,
  `provider_code` varchar(50) NOT NULL DEFAULT '',
  `ticket_required` tinyint(1) NOT NULL DEFAULT '0',
  `priority` int(11) NOT NULL DEFAULT '100',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `hits` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_assistant_messages`
--

CREATE TABLE `cv_assistant_messages` (
  `id_message` bigint(20) UNSIGNED NOT NULL,
  `id_conversation` bigint(20) UNSIGNED NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'assistant',
  `message_text` mediumtext NOT NULL,
  `intent` varchar(64) NOT NULL DEFAULT '',
  `confidence` decimal(5,2) NOT NULL DEFAULT '0.00',
  `meta_json` mediumtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_assistant_settings`
--

CREATE TABLE `cv_assistant_settings` (
  `id_sett` bigint(20) UNSIGNED NOT NULL,
  `assistant_name` varchar(120) NOT NULL DEFAULT '',
  `assistant_badge` varchar(120) NOT NULL DEFAULT '',
  `welcome_message` text NOT NULL,
  `fallback_message` text NOT NULL,
  `escalation_message` text NOT NULL,
  `quick_replies_json` text,
  `widget_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `collect_logs` tinyint(1) NOT NULL DEFAULT '1',
  `learning_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `feedback_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `ticketing_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `recovery_email_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `operator_handoff_after_unresolved` int(11) NOT NULL DEFAULT '4',
  `operator_handoff_label` varchar(120) NOT NULL DEFAULT 'Chatta con un operatore',
  `operator_busy_timeout_minutes` int(11) NOT NULL DEFAULT '6',
  `updated_by` varchar(190) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_assistant_support_messages`
--

CREATE TABLE `cv_assistant_support_messages` (
  `id_ticket_message` bigint(20) UNSIGNED NOT NULL,
  `id_ticket` bigint(20) UNSIGNED NOT NULL,
  `sender_role` varchar(20) NOT NULL DEFAULT 'user',
  `sender_name` varchar(190) NOT NULL DEFAULT '',
  `message_text` mediumtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_assistant_support_tickets`
--

CREATE TABLE `cv_assistant_support_tickets` (
  `id_ticket` bigint(20) UNSIGNED NOT NULL,
  `session_key` varchar(80) NOT NULL DEFAULT '',
  `id_conversation` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `channel` varchar(32) NOT NULL DEFAULT 'web',
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `subject` varchar(190) NOT NULL DEFAULT '',
  `customer_name` varchar(190) NOT NULL DEFAULT '',
  `customer_email` varchar(190) NOT NULL DEFAULT '',
  `customer_phone` varchar(50) NOT NULL DEFAULT '',
  `provider_code` varchar(50) NOT NULL DEFAULT '',
  `ticket_code` varchar(80) NOT NULL DEFAULT '',
  `created_by` varchar(190) NOT NULL DEFAULT '',
  `last_message_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_backend_users`
--

CREATE TABLE `cv_backend_users` (
  `id_user` int(10) UNSIGNED NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_encrypted` text COLLATE utf8mb4_unicode_ci,
  `role` enum('admin','provider') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'provider',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_backend_user_providers`
--

CREATE TABLE `cv_backend_user_providers` (
  `id_user_provider` int(10) UNSIGNED NOT NULL,
  `id_user` int(10) UNSIGNED NOT NULL,
  `provider_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_blog_posts`
--

CREATE TABLE `cv_blog_posts` (
  `id_blog_post` bigint(20) UNSIGNED NOT NULL,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `excerpt` text COLLATE utf8mb4_unicode_ci,
  `content_html` mediumtext COLLATE utf8mb4_unicode_ci,
  `content_blocks_json` longtext COLLATE utf8mb4_unicode_ci,
  `hero_image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT '100',
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_email_verifications`
--

CREATE TABLE `cv_email_verifications` (
  `id_verification` bigint(20) UNSIGNED NOT NULL,
  `id_vg` int(11) NOT NULL,
  `email` varchar(190) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `resend_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_error_log`
--

CREATE TABLE `cv_error_log` (
  `id_error_log` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_code` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('warning','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'error',
  `message` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_code` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shop_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `context_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_home_provider_featured_routes`
--

CREATE TABLE `cv_home_provider_featured_routes` (
  `id_featured_route` bigint(20) UNSIGNED NOT NULL,
  `provider_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_stop_external_id` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_stop_external_id` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_idempotency_keys`
--

CREATE TABLE `cv_idempotency_keys` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key_hash` char(64) NOT NULL,
  `endpoint` varchar(64) NOT NULL,
  `order_id` bigint(20) UNSIGNED DEFAULT NULL,
  `request_hash` char(64) NOT NULL,
  `response_status` smallint(5) UNSIGNED NOT NULL,
  `response_body` longtext NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_newsletter_campaigns`
--

CREATE TABLE `cv_newsletter_campaigns` (
  `id_campaign` bigint(20) UNSIGNED NOT NULL,
  `subject` varchar(190) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `body_text` text NOT NULL,
  `recipients_total` int(11) NOT NULL DEFAULT '0',
  `recipients_sent` int(11) NOT NULL DEFAULT '0',
  `recipients_failed` int(11) NOT NULL DEFAULT '0',
  `status` varchar(32) NOT NULL DEFAULT 'completed',
  `created_by` varchar(190) NOT NULL DEFAULT '',
  `fail_log` mediumtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_newsletter_guest_subscriptions`
--

CREATE TABLE `cv_newsletter_guest_subscriptions` (
  `id_guest_subscription` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `subscribed` tinyint(1) NOT NULL DEFAULT '0',
  `source` varchar(64) NOT NULL DEFAULT 'guest',
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_newsletter_guest_verifications`
--

CREATE TABLE `cv_newsletter_guest_verifications` (
  `id_news_verify` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_newsletter_subscriptions`
--

CREATE TABLE `cv_newsletter_subscriptions` (
  `id_subscription` bigint(20) UNSIGNED NOT NULL,
  `id_vg` int(11) NOT NULL,
  `email` varchar(190) NOT NULL,
  `subscribed` tinyint(1) NOT NULL DEFAULT '0',
  `source` varchar(64) NOT NULL DEFAULT 'web',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_orders`
--

CREATE TABLE `cv_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_code` varchar(40) NOT NULL,
  `user_ref` varchar(80) DEFAULT NULL,
  `currency` char(3) NOT NULL DEFAULT 'EUR',
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_mode` enum('provider_direct','marketplace_split','marketplace_single') NOT NULL DEFAULT 'marketplace_split',
  `status` enum('draft','reserved','payment_pending','paid','failed','cancelled','expired','refunded','partially_refunded') NOT NULL DEFAULT 'draft',
  `idempotency_key` varchar(120) DEFAULT NULL,
  `search_context` longtext,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_order_legs`
--

CREATE TABLE `cv_order_legs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `direction` enum('outbound','inbound') NOT NULL,
  `leg_index` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `provider_shop_id` varchar(80) DEFAULT NULL,
  `provider_booking_code` varchar(80) DEFAULT NULL,
  `id_linea` int(10) UNSIGNED DEFAULT NULL,
  `id_corsa` int(10) UNSIGNED DEFAULT NULL,
  `id_sott1` int(10) UNSIGNED NOT NULL,
  `id_sott2` int(10) UNSIGNED NOT NULL,
  `departure_at` datetime NOT NULL,
  `arrival_at` datetime NOT NULL,
  `fare_code` varchar(64) DEFAULT NULL,
  `passengers_json` longtext,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `commission_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','reserved','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'draft',
  `raw_response` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_password_resets`
--

CREATE TABLE `cv_password_resets` (
  `id_reset` bigint(20) UNSIGNED NOT NULL,
  `id_vg` int(11) NOT NULL,
  `email` varchar(190) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `pending_password_hash` varchar(255) NOT NULL DEFAULT '',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_payment_settings`
--

CREATE TABLE `cv_payment_settings` (
  `id_setting` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_type` enum('string','int','float','bool','json') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_payment_splits`
--

CREATE TABLE `cv_payment_splits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payment_tx_id` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED DEFAULT NULL,
  `split_type` enum('provider_amount','platform_fee','gateway_fee','tax') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','settled','failed') NOT NULL DEFAULT 'pending',
  `settled_at` datetime DEFAULT NULL,
  `meta_json` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_payment_transactions`
--

CREATE TABLE `cv_payment_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `gateway` varchar(32) NOT NULL,
  `transaction_ref` varchar(128) NOT NULL,
  `provider_ref` varchar(128) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'EUR',
  `status` enum('created','authorized','captured','failed','voided','refunded','partially_refunded') NOT NULL DEFAULT 'created',
  `raw_request` longtext,
  `raw_response` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_places`
--

CREATE TABLE `cv_places` (
  `id_place` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `place_type` enum('macroarea','city','district','station_group','province','region') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'macroarea',
  `parent_id_place` bigint(20) UNSIGNED DEFAULT NULL,
  `province_code` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IT',
  `lat` decimal(10,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `radius_km` decimal(6,2) NOT NULL DEFAULT '15.00',
  `search_weight` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `demand_score` decimal(12,2) NOT NULL DEFAULT '0.00',
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `is_auto` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `review_status` enum('auto','reviewed','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `is_locked` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `last_generation_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_place_aliases`
--

CREATE TABLE `cv_place_aliases` (
  `id_alias` bigint(20) UNSIGNED NOT NULL,
  `id_place` bigint(20) UNSIGNED NOT NULL,
  `alias` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_alias` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `search_weight` smallint(5) UNSIGNED NOT NULL DEFAULT '100',
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_place_generation_runs`
--

CREATE TABLE `cv_place_generation_runs` (
  `id_run` bigint(20) UNSIGNED NOT NULL,
  `status` enum('running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `algorithm_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v1',
  `source_stops_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `generated_places_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `generated_links_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_place_metrics`
--

CREATE TABLE `cv_place_metrics` (
  `id_place` bigint(20) UNSIGNED NOT NULL,
  `departures_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `arrivals_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `searches_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `bookings_count` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `popularity_score` decimal(12,2) NOT NULL DEFAULT '0.00',
  `refreshed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_place_name_overrides`
--

CREATE TABLE `cv_place_name_overrides` (
  `id_override` bigint(20) UNSIGNED NOT NULL,
  `place_code` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `manual_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_place_stops`
--

CREATE TABLE `cv_place_stops` (
  `id_place_stop` bigint(20) UNSIGNED NOT NULL,
  `id_place` bigint(20) UNSIGNED NOT NULL,
  `id_stop` bigint(20) UNSIGNED NOT NULL,
  `match_type` enum('primary','secondary','nearby','manual','auto_cluster') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto_cluster',
  `source` enum('auto','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `distance_km` decimal(7,3) NOT NULL DEFAULT '0.000',
  `match_score` decimal(6,3) DEFAULT NULL,
  `priority` smallint(5) UNSIGNED NOT NULL DEFAULT '100',
  `is_primary` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `is_locked` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_place_stop_overrides`
--

CREATE TABLE `cv_place_stop_overrides` (
  `id_override` bigint(20) UNSIGNED NOT NULL,
  `id_stop` bigint(20) UNSIGNED NOT NULL,
  `action` enum('force_include','exclude','primary') COLLATE utf8mb4_unicode_ci NOT NULL,
  `forced_place_code` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `forced_place_name` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `priority` smallint(5) UNSIGNED DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_promotions`
--

CREATE TABLE `cv_promotions` (
  `id_promo` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `mode` enum('auto','code') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'code',
  `visibility` enum('public','hidden') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hidden',
  `provider_codes` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `days_of_week` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `valid_from` datetime DEFAULT NULL,
  `valid_to` datetime DEFAULT NULL,
  `priority` smallint(5) UNSIGNED NOT NULL DEFAULT '100',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_providers`
--

CREATE TABLE `cv_providers` (
  `id_provider` int(10) UNSIGNED NOT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(150) NOT NULL,
  `base_url` varchar(255) NOT NULL,
  `api_key` varchar(255) DEFAULT NULL,
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `last_sync_at` datetime DEFAULT NULL,
  `last_error` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_provider_fares`
--

CREATE TABLE `cv_provider_fares` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_provider` int(10) UNSIGNED NOT NULL,
  `external_id` varchar(64) NOT NULL,
  `from_stop_external_id` varchar(64) NOT NULL,
  `to_stop_external_id` varchar(64) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) NOT NULL DEFAULT 'EUR',
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `source_updated_at` datetime DEFAULT NULL,
  `synced_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `raw_json` longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_provider_lines`
--

CREATE TABLE `cv_provider_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_provider` int(10) UNSIGNED NOT NULL,
  `external_id` varchar(64) NOT NULL,
  `name` varchar(160) NOT NULL,
  `color` varchar(32) DEFAULT NULL,
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `is_visible` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `source_updated_at` datetime DEFAULT NULL,
  `synced_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `raw_json` longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_provider_stops`
--

CREATE TABLE `cv_provider_stops` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_provider` int(10) UNSIGNED NOT NULL,
  `external_id` varchar(64) NOT NULL,
  `name` varchar(160) NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `source_updated_at` datetime DEFAULT NULL,
  `synced_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `raw_json` longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_provider_trips`
--

CREATE TABLE `cv_provider_trips` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_provider` int(10) UNSIGNED NOT NULL,
  `external_id` varchar(64) NOT NULL,
  `line_external_id` varchar(64) DEFAULT NULL,
  `name` varchar(200) DEFAULT NULL,
  `tempo_acquisto` smallint(5) UNSIGNED NOT NULL DEFAULT '30',
  `direction_id` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `is_visible` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `source_updated_at` datetime DEFAULT NULL,
  `synced_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `raw_json` longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_provider_trip_stops`
--

CREATE TABLE `cv_provider_trip_stops` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_provider` int(10) UNSIGNED NOT NULL,
  `trip_external_id` varchar(64) NOT NULL,
  `sequence_no` int(10) UNSIGNED NOT NULL,
  `stop_external_id` varchar(64) NOT NULL,
  `time_local` time DEFAULT NULL,
  `day_offset` smallint(6) NOT NULL DEFAULT '0',
  `is_active` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `synced_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `raw_json` longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_route_seo_pages`
--

CREATE TABLE `cv_route_seo_pages` (
  `id_route_seo_page` bigint(20) UNSIGNED NOT NULL,
  `slug` varchar(191) NOT NULL,
  `from_ref` varchar(191) NOT NULL,
  `to_ref` varchar(191) NOT NULL,
  `from_name` varchar(255) NOT NULL,
  `to_name` varchar(255) NOT NULL,
  `search_count_snapshot` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `last_requested_at` datetime DEFAULT NULL,
  `last_travel_date_it` varchar(10) DEFAULT NULL,
  `min_amount` decimal(10,2) DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'EUR',
  `title_override` varchar(255) DEFAULT NULL,
  `meta_description_override` varchar(320) DEFAULT NULL,
  `intro_override` text,
  `body_override` mediumtext,
  `hero_image_path` varchar(255) DEFAULT NULL,
  `auto_title` varchar(255) NOT NULL,
  `auto_meta_description` varchar(320) NOT NULL,
  `auto_intro` text NOT NULL,
  `auto_body` mediumtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_search_cache`
--

CREATE TABLE `cv_search_cache` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `cache_key` char(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `from_stop_id` int(10) UNSIGNED DEFAULT NULL,
  `to_stop_id` int(10) UNSIGNED DEFAULT NULL,
  `travel_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `pax_adults` smallint(5) UNSIGNED NOT NULL DEFAULT '1',
  `pax_children` smallint(5) UNSIGNED NOT NULL DEFAULT '0',
  `source_companies` varchar(255) NOT NULL,
  `response_json` longtext NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_search_route_stats`
--

CREATE TABLE `cv_search_route_stats` (
  `id_route_stat` bigint(20) UNSIGNED NOT NULL,
  `from_ref` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_ref` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_provider_code` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_stop_external_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_provider_code` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_stop_external_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `search_count` bigint(20) UNSIGNED NOT NULL DEFAULT '1',
  `first_requested_at` datetime NOT NULL,
  `last_requested_at` datetime NOT NULL,
  `last_travel_date_it` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_mode` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_adults` int(10) UNSIGNED NOT NULL DEFAULT '1',
  `last_children` int(10) UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_settings`
--

CREATE TABLE `cv_settings` (
  `id_setting` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_type` enum('string','int','float','bool','json') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_sync_runs`
--

CREATE TABLE `cv_sync_runs` (
  `id_run` bigint(20) UNSIGNED NOT NULL,
  `id_provider` int(10) UNSIGNED NOT NULL,
  `status` enum('running','ok','error') NOT NULL DEFAULT 'running',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` datetime DEFAULT NULL,
  `details_json` longtext,
  `error_message` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `cv_ticket_recovery_requests`
--

CREATE TABLE `cv_ticket_recovery_requests` (
  `id_request` bigint(20) UNSIGNED NOT NULL,
  `session_key` varchar(80) NOT NULL DEFAULT '',
  `email` varchar(190) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `ticket_codes_json` mediumtext NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `linee`
--

CREATE TABLE `linee` (
  `id_linea` int(10) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `nome` varchar(120) NOT NULL,
  `colore` varchar(20) DEFAULT NULL,
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `visualizzato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `mail_sett`
--

CREATE TABLE `mail_sett` (
  `id_sett` int(11) NOT NULL,
  `email1` varchar(190) NOT NULL DEFAULT '',
  `user1` varchar(190) NOT NULL DEFAULT '',
  `pass1` varchar(255) NOT NULL DEFAULT '',
  `oggetto1` varchar(190) NOT NULL DEFAULT '',
  `email2` varchar(190) NOT NULL DEFAULT '',
  `user2` varchar(190) NOT NULL DEFAULT '',
  `pass2` varchar(255) NOT NULL DEFAULT '',
  `oggetto2` varchar(190) NOT NULL DEFAULT '',
  `email3` varchar(190) NOT NULL DEFAULT '',
  `user3` varchar(190) NOT NULL DEFAULT '',
  `pass3` varchar(255) NOT NULL DEFAULT '',
  `oggetto3` varchar(190) NOT NULL DEFAULT '',
  `smtp` varchar(190) NOT NULL DEFAULT '',
  `smtpport` int(11) NOT NULL DEFAULT '0',
  `smtpsecurity` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `mezzi`
--

CREATE TABLE `mezzi` (
  `id_mz` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_mztipo` bigint(20) UNSIGNED DEFAULT NULL,
  `nome` varchar(100) NOT NULL,
  `targa` varchar(20) NOT NULL,
  `posti` smallint(5) UNSIGNED NOT NULL DEFAULT '57',
  `anno` date DEFAULT '1970-01-01',
  `foto` varchar(255) DEFAULT '-',
  `immagine_veicolo` varchar(255) DEFAULT '-',
  `nbici` smallint(5) UNSIGNED DEFAULT '0',
  `ncarr` smallint(5) UNSIGNED DEFAULT '0',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `mezzi_corse`
--

CREATE TABLE `mezzi_corse` (
  `id_mzc` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_mz` bigint(20) UNSIGNED NOT NULL,
  `id_corsa` int(10) UNSIGNED NOT NULL,
  `da` date NOT NULL,
  `a` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `mezzi_date`
--

CREATE TABLE `mezzi_date` (
  `id_mz_dt` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `data` date NOT NULL,
  `al` date DEFAULT '1970-01-01',
  `id_linea` int(10) UNSIGNED DEFAULT '1',
  `id_corsa` varchar(255) DEFAULT '1',
  `n` smallint(5) UNSIGNED NOT NULL,
  `posti` smallint(5) UNSIGNED NOT NULL DEFAULT '57',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `mezzi_mappe`
--

CREATE TABLE `mezzi_mappe` (
  `id_mztipo` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `nome` varchar(150) NOT NULL,
  `def` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `piani` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `posti1` smallint(5) UNSIGNED NOT NULL,
  `posti2` smallint(5) UNSIGNED NOT NULL DEFAULT '0',
  `str` varchar(255) DEFAULT '',
  `nbici` smallint(5) UNSIGNED NOT NULL DEFAULT '0',
  `ncarr` smallint(5) UNSIGNED NOT NULL DEFAULT '0',
  `str_posti` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `payment_errors`
--

CREATE TABLE `payment_errors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED DEFAULT NULL,
  `id_vg` bigint(20) UNSIGNED DEFAULT '0',
  `event_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `order_id` varchar(255) DEFAULT NULL,
  `error_message` text,
  `payload` longtext,
  `liability` varchar(255) DEFAULT NULL,
  `cardholder_name` varchar(255) DEFAULT NULL,
  `card_number_last4` varchar(4) DEFAULT NULL,
  `card_type` varchar(50) DEFAULT NULL,
  `user_ip` varchar(45) DEFAULT NULL,
  `user_agent` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `provReg`
--

CREATE TABLE `provReg` (
  `id_prov` int(11) NOT NULL,
  `provincia` varchar(23) NOT NULL,
  `regione` varchar(23) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Struttura della tabella `prz_bag`
--

CREATE TABLE `prz_bag` (
  `id_przbg` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `txt` varchar(100) DEFAULT '-',
  `da` date NOT NULL,
  `a` date NOT NULL,
  `peso` varchar(200) NOT NULL,
  `dim` varchar(50) NOT NULL,
  `prz` decimal(10,2) NOT NULL DEFAULT '0.00',
  `max_qnt` int(10) UNSIGNED NOT NULL DEFAULT '5',
  `incremento` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `info` varchar(255) DEFAULT '',
  `tipobg` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `prz_date`
--

CREATE TABLE `prz_date` (
  `id_przdt` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `da` date NOT NULL,
  `a` date NOT NULL,
  `corsa` varchar(255) NOT NULL,
  `ad` decimal(10,2) NOT NULL DEFAULT '1.00',
  `bam` decimal(10,2) NOT NULL DEFAULT '1.00',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `pst_date`
--

CREATE TABLE `pst_date` (
  `id_pst` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `da` date NOT NULL,
  `a` date NOT NULL,
  `corsa` varchar(255) NOT NULL,
  `posti` varchar(255) NOT NULL,
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `pst_prz`
--

CREATE TABLE `pst_prz` (
  `id_pstprz` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `tipo` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `prz` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `regole`
--

CREATE TABLE `regole` (
  `id_r` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_sott1` int(10) UNSIGNED NOT NULL,
  `id_sott2` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `id_linea` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `date` text,
  `date_permesse` text,
  `giorni_sett` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `regole_corse`
--

CREATE TABLE `regole_corse` (
  `id_rc` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_sott` int(10) UNSIGNED NOT NULL,
  `corse` varchar(255) DEFAULT '0',
  `giorni_sett` varchar(50) NOT NULL DEFAULT '0',
  `da` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `a` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `regole_linee`
--

CREATE TABLE `regole_linee` (
  `id_rl` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_linea` int(10) UNSIGNED NOT NULL,
  `corse` varchar(255) DEFAULT '0',
  `da` datetime NOT NULL,
  `a` datetime NOT NULL,
  `giorni_sett` varchar(50) DEFAULT '-',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `regole_tratta`
--

CREATE TABLE `regole_tratta` (
  `id_rtr` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_sott1` int(10) UNSIGNED NOT NULL,
  `id_sott2` int(10) UNSIGNED NOT NULL,
  `impedite_permesse` tinyint(3) UNSIGNED NOT NULL,
  `corse` varchar(255) NOT NULL,
  `da` date NOT NULL,
  `a` date NOT NULL,
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `sconti`
--

CREATE TABLE `sconti` (
  `id_cod` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `nome` varchar(255) DEFAULT '-',
  `codice` varchar(20) NOT NULL,
  `sconto` decimal(5,2) NOT NULL DEFAULT '0.00',
  `partenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `scadenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `giorni_sett` varchar(20) DEFAULT '-',
  `autom` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `ar` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `pst` int(10) UNSIGNED NOT NULL DEFAULT '57',
  `id_linea` varchar(100) DEFAULT '0',
  `id_corsa` varchar(500) DEFAULT '0',
  `modific` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `avviso` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `avviso_str` varchar(255) DEFAULT NULL,
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `tratte_sottoc`
--

CREATE TABLE `tratte_sottoc` (
  `id_sott` int(10) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `nome` varchar(120) NOT NULL,
  `descsott` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `localita` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `sos_da` datetime DEFAULT '1970-01-01 00:00:00',
  `sos_a` datetime DEFAULT '1970-01-01 00:00:00',
  `indirizzo` varchar(255) DEFAULT NULL,
  `comune` varchar(100) DEFAULT NULL,
  `provincia` varchar(50) DEFAULT NULL,
  `paese` varchar(50) DEFAULT NULL,
  `country_code` char(2) DEFAULT 'IT',
  `timezone` varchar(50) DEFAULT 'Europe/Rome',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `tratte_sottoc_tratte`
--

CREATE TABLE `tratte_sottoc_tratte` (
  `id_tst` bigint(20) UNSIGNED NOT NULL,
  `id_az` int(10) UNSIGNED NOT NULL,
  `id_sott1` int(10) UNSIGNED NOT NULL,
  `id_sott2` int(10) UNSIGNED NOT NULL,
  `prezzo` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `viaggiatori`
--

CREATE TABLE `viaggiatori` (
  `id_vg` bigint(20) UNSIGNED NOT NULL,
  `nome` varchar(50) NOT NULL,
  `cognome` varchar(50) DEFAULT '-',
  `citta` varchar(255) DEFAULT '-',
  `id_prov` int(10) UNSIGNED DEFAULT '0',
  `picf` varchar(20) DEFAULT '-',
  `email` varchar(150) DEFAULT '-',
  `pass` varchar(255) DEFAULT '-',
  `foto` varchar(200) DEFAULT 'default.jpg',
  `tel` varchar(30) NOT NULL,
  `data` date DEFAULT '1970-01-01',
  `profilo` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `sconto` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tipo_pag` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
  `conteggio_pag` int(10) UNSIGNED NOT NULL DEFAULT '1',
  `comunicazioni` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `google_userid` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struttura della tabella `viaggiatori_temp`
--

CREATE TABLE `viaggiatori_temp` (
  `id_vgt` bigint(20) UNSIGNED NOT NULL,
  `nome` varchar(50) NOT NULL,
  `cognome` varchar(50) NOT NULL,
  `tel` varchar(30) NOT NULL,
  `email` varchar(150) DEFAULT '-',
  `data_reg` date DEFAULT '1970-01-01',
  `stato` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indici per le tabelle scaricate
--

--
-- Indici per le tabelle `abbcarn_lista`
--
ALTER TABLE `abbcarn_lista`
  ADD PRIMARY KEY (`id_codabbcarn_l`),
  ADD KEY `idx_abbcarn_lista_az_stato` (`id_az`,`stato`);

--
-- Indici per le tabelle `abbcarn_utenti`
--
ALTER TABLE `abbcarn_utenti`
  ADD PRIMARY KEY (`id_codabbcarn_u`),
  ADD KEY `idx_abbcarn_utenti_tx` (`transaction_id`),
  ADD KEY `idx_abbcarn_utenti_cod` (`codice_u`),
  ADD KEY `fk_abbcarn_utenti_azienda` (`id_az`),
  ADD KEY `fk_abbcarn_utenti_lista` (`id_codabbcarn_l`),
  ADD KEY `fk_abbcarn_utenti_vg` (`id_vg`);

--
-- Indici per le tabelle `abbcarn_utenti_reg`
--
ALTER TABLE `abbcarn_utenti_reg`
  ADD PRIMARY KEY (`id_abbcarn_reg`),
  ADD KEY `idx_abbcarn_utenti_reg_u` (`id_codabbcarn_u`);

--
-- Indici per le tabelle `aziende`
--
ALTER TABLE `aziende`
  ADD PRIMARY KEY (`id_az`),
  ADD UNIQUE KEY `uq_aziende_code` (`code`),
  ADD KEY `idx_aziende_stato` (`stato`);

--
-- Indici per le tabelle `aziende_comm`
--
ALTER TABLE `aziende_comm`
  ADD PRIMARY KEY (`id_azcomm`),
  ADD UNIQUE KEY `uq_aziende_comm_idaz` (`id_az`);

--
-- Indici per le tabelle `aziende_pag2`
--
ALTER TABLE `aziende_pag2`
  ADD PRIMARY KEY (`az_p`),
  ADD UNIQUE KEY `uq_aziende_pag2_az_tipo_env` (`id_az`,`tipo`,`environment`),
  ADD KEY `idx_aziende_pag2_stato` (`stato`,`stato_app`);

--
-- Indici per le tabelle `biglietti`
--
ALTER TABLE `biglietti`
  ADD PRIMARY KEY (`id_bg`),
  ADD KEY `idx_biglietti_codice` (`codice`),
  ADD KEY `idx_biglietti_tx` (`transaction_id`),
  ADD KEY `idx_biglietti_shop_status` (`transaction_id`,`pagato`,`stato`),
  ADD KEY `idx_biglietti_route_time` (`id_az`,`id_corsa`,`data`),
  ADD KEY `idx_biglietti_user` (`id_vg`,`id_vgt`),
  ADD KEY `fk_biglietti_linea` (`id_linea`),
  ADD KEY `fk_biglietti_corsa` (`id_corsa`),
  ADD KEY `fk_biglietti_sott1` (`id_sott1`),
  ADD KEY `fk_biglietti_sott2` (`id_sott2`),
  ADD KEY `fk_biglietti_vgt` (`id_vgt`);

--
-- Indici per le tabelle `biglietti_log`
--
ALTER TABLE `biglietti_log`
  ADD PRIMARY KEY (`id_b_log`),
  ADD KEY `idx_biglietti_log_bg` (`id_bg`);

--
-- Indici per le tabelle `biglietti_reg`
--
ALTER TABLE `biglietti_reg`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_biglietti_reg_bg` (`id_bg`);

--
-- Indici per le tabelle `corse`
--
ALTER TABLE `corse`
  ADD PRIMARY KEY (`id_corsa`),
  ADD KEY `idx_corse_az_stato` (`id_az`,`stato`),
  ADD KEY `idx_corse_linea` (`id_linea`);

--
-- Indici per le tabelle `corse_fermate`
--
ALTER TABLE `corse_fermate`
  ADD PRIMARY KEY (`id_corse_f`),
  ADD UNIQUE KEY `uq_corse_fermate` (`id_corsa`,`id_sott`,`ordine`),
  ADD KEY `idx_corse_fermate_stop` (`id_sott`),
  ADD KEY `idx_corse_fermate_route` (`id_corsa`,`ordine`),
  ADD KEY `fk_corse_fermate_azienda` (`id_az`);

--
-- Indici per le tabelle `cv_api_logs`
--
ALTER TABLE `cv_api_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cv_api_logs_created` (`created_at`),
  ADD KEY `idx_cv_api_logs_company` (`id_az`,`endpoint`);

--
-- Indici per le tabelle `cv_assistant_conversations`
--
ALTER TABLE `cv_assistant_conversations`
  ADD PRIMARY KEY (`id_conversation`),
  ADD UNIQUE KEY `uniq_cv_assistant_session` (`session_key`),
  ADD KEY `idx_cv_assistant_conversations_last` (`last_message_at`),
  ADD KEY `idx_cv_assistant_conversations_ticket` (`ticket_code`),
  ADD KEY `idx_cv_assistant_conversations_provider` (`provider_code`);

--
-- Indici per le tabelle `cv_assistant_feedback`
--
ALTER TABLE `cv_assistant_feedback`
  ADD PRIMARY KEY (`id_feedback`),
  ADD UNIQUE KEY `uq_cv_assistant_feedback_message_session` (`id_message`,`session_key`),
  ADD KEY `idx_cv_assistant_feedback_message` (`id_message`),
  ADD KEY `idx_cv_assistant_feedback_session` (`session_key`);

--
-- Indici per le tabelle `cv_assistant_knowledge`
--
ALTER TABLE `cv_assistant_knowledge`
  ADD PRIMARY KEY (`id_knowledge`),
  ADD KEY `idx_cv_assistant_knowledge_active` (`active`,`priority`),
  ADD KEY `idx_cv_assistant_knowledge_provider` (`provider_code`);

--
-- Indici per le tabelle `cv_assistant_messages`
--
ALTER TABLE `cv_assistant_messages`
  ADD PRIMARY KEY (`id_message`),
  ADD KEY `idx_cv_assistant_messages_conversation` (`id_conversation`,`created_at`);

--
-- Indici per le tabelle `cv_assistant_settings`
--
ALTER TABLE `cv_assistant_settings`
  ADD PRIMARY KEY (`id_sett`);

--
-- Indici per le tabelle `cv_assistant_support_messages`
--
ALTER TABLE `cv_assistant_support_messages`
  ADD PRIMARY KEY (`id_ticket_message`),
  ADD KEY `idx_cv_assistant_support_messages_ticket` (`id_ticket`,`created_at`);

--
-- Indici per le tabelle `cv_assistant_support_tickets`
--
ALTER TABLE `cv_assistant_support_tickets`
  ADD PRIMARY KEY (`id_ticket`),
  ADD KEY `idx_cv_assistant_support_status` (`status`,`last_message_at`),
  ADD KEY `idx_cv_assistant_support_session` (`session_key`),
  ADD KEY `idx_cv_assistant_support_provider` (`provider_code`),
  ADD KEY `idx_cv_assistant_support_ticket` (`ticket_code`);

--
-- Indici per le tabelle `cv_backend_users`
--
ALTER TABLE `cv_backend_users`
  ADD PRIMARY KEY (`id_user`),
  ADD UNIQUE KEY `uk_cv_backend_users_email` (`email`),
  ADD KEY `idx_cv_backend_users_role_active` (`role`,`is_active`);

--
-- Indici per le tabelle `cv_backend_user_providers`
--
ALTER TABLE `cv_backend_user_providers`
  ADD PRIMARY KEY (`id_user_provider`),
  ADD UNIQUE KEY `uk_cv_backend_user_provider` (`id_user`,`provider_code`),
  ADD KEY `idx_cv_backend_provider_code` (`provider_code`);

--
-- Indici per le tabelle `cv_blog_posts`
--
ALTER TABLE `cv_blog_posts`
  ADD PRIMARY KEY (`id_blog_post`),
  ADD UNIQUE KEY `uq_cv_blog_posts_slug` (`slug`),
  ADD KEY `idx_cv_blog_posts_status` (`status`,`published_at`),
  ADD KEY `idx_cv_blog_posts_order` (`sort_order`,`updated_at`);

--
-- Indici per le tabelle `cv_email_verifications`
--
ALTER TABLE `cv_email_verifications`
  ADD PRIMARY KEY (`id_verification`),
  ADD UNIQUE KEY `uniq_email_verify_user` (`id_vg`),
  ADD UNIQUE KEY `uniq_email_verify_token_hash` (`token_hash`),
  ADD KEY `idx_email_verify_email` (`email`),
  ADD KEY `idx_email_verify_expires` (`expires_at`);

--
-- Indici per le tabelle `cv_error_log`
--
ALTER TABLE `cv_error_log`
  ADD PRIMARY KEY (`id_error_log`),
  ADD KEY `idx_cv_error_log_created` (`created_at`),
  ADD KEY `idx_cv_error_log_event` (`source`,`event_code`,`created_at`),
  ADD KEY `idx_cv_error_log_provider` (`provider_code`,`created_at`);

--
-- Indici per le tabelle `cv_home_provider_featured_routes`
--
ALTER TABLE `cv_home_provider_featured_routes`
  ADD PRIMARY KEY (`id_featured_route`),
  ADD UNIQUE KEY `uq_cv_home_provider_featured_route` (`provider_code`,`from_stop_external_id`,`to_stop_external_id`),
  ADD KEY `idx_cv_home_provider_featured_provider` (`provider_code`,`is_active`,`sort_order`);

--
-- Indici per le tabelle `cv_idempotency_keys`
--
ALTER TABLE `cv_idempotency_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cv_idempotency_key` (`key_hash`),
  ADD KEY `idx_cv_idempotency_exp` (`expires_at`),
  ADD KEY `fk_cv_idem_order` (`order_id`);

--
-- Indici per le tabelle `cv_newsletter_campaigns`
--
ALTER TABLE `cv_newsletter_campaigns`
  ADD PRIMARY KEY (`id_campaign`),
  ADD KEY `idx_news_campaign_created` (`created_at`);

--
-- Indici per le tabelle `cv_newsletter_guest_subscriptions`
--
ALTER TABLE `cv_newsletter_guest_subscriptions`
  ADD PRIMARY KEY (`id_guest_subscription`),
  ADD UNIQUE KEY `uq_news_guest_email` (`email`),
  ADD KEY `idx_news_guest_subscribed` (`subscribed`);

--
-- Indici per le tabelle `cv_newsletter_guest_verifications`
--
ALTER TABLE `cv_newsletter_guest_verifications`
  ADD PRIMARY KEY (`id_news_verify`),
  ADD UNIQUE KEY `uq_news_verify_email` (`email`),
  ADD UNIQUE KEY `uq_news_verify_token` (`token_hash`),
  ADD KEY `idx_news_verify_expires` (`expires_at`);

--
-- Indici per le tabelle `cv_newsletter_subscriptions`
--
ALTER TABLE `cv_newsletter_subscriptions`
  ADD PRIMARY KEY (`id_subscription`),
  ADD UNIQUE KEY `uniq_news_user` (`id_vg`),
  ADD KEY `idx_news_email` (`email`),
  ADD KEY `idx_news_subscribed` (`subscribed`);

--
-- Indici per le tabelle `cv_orders`
--
ALTER TABLE `cv_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cv_orders_code` (`order_code`),
  ADD UNIQUE KEY `uq_cv_orders_idem` (`idempotency_key`),
  ADD KEY `idx_cv_orders_status` (`status`),
  ADD KEY `idx_cv_orders_exp` (`expires_at`);

--
-- Indici per le tabelle `cv_order_legs`
--
ALTER TABLE `cv_order_legs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cv_order_legs_order` (`order_id`),
  ADD KEY `idx_cv_order_legs_company` (`id_az`);

--
-- Indici per le tabelle `cv_password_resets`
--
ALTER TABLE `cv_password_resets`
  ADD PRIMARY KEY (`id_reset`),
  ADD UNIQUE KEY `uq_cv_password_resets_token` (`token_hash`),
  ADD KEY `idx_cv_password_resets_user` (`id_vg`,`used_at`),
  ADD KEY `idx_cv_password_resets_exp` (`expires_at`);

--
-- Indici per le tabelle `cv_payment_settings`
--
ALTER TABLE `cv_payment_settings`
  ADD PRIMARY KEY (`id_setting`),
  ADD UNIQUE KEY `uq_cv_payment_settings_key` (`setting_key`);

--
-- Indici per le tabelle `cv_payment_splits`
--
ALTER TABLE `cv_payment_splits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cv_splits_tx` (`payment_tx_id`),
  ADD KEY `idx_cv_splits_company` (`id_az`);

--
-- Indici per le tabelle `cv_payment_transactions`
--
ALTER TABLE `cv_payment_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cv_pay_tx_ref` (`transaction_ref`),
  ADD KEY `idx_cv_pay_tx_order` (`order_id`);

--
-- Indici per le tabelle `cv_places`
--
ALTER TABLE `cv_places`
  ADD PRIMARY KEY (`id_place`),
  ADD UNIQUE KEY `uq_cv_places_code` (`code`),
  ADD KEY `idx_cv_places_name` (`normalized_name`,`is_active`),
  ADD KEY `idx_cv_places_type_weight` (`place_type`,`is_active`,`search_weight`,`demand_score`),
  ADD KEY `idx_cv_places_parent` (`parent_id_place`),
  ADD KEY `idx_cv_places_review` (`review_status`,`is_locked`);

--
-- Indici per le tabelle `cv_place_aliases`
--
ALTER TABLE `cv_place_aliases`
  ADD PRIMARY KEY (`id_alias`),
  ADD UNIQUE KEY `uq_cv_place_aliases_place_alias` (`id_place`,`normalized_alias`),
  ADD KEY `idx_cv_place_aliases_lookup` (`normalized_alias`,`is_active`,`search_weight`);

--
-- Indici per le tabelle `cv_place_generation_runs`
--
ALTER TABLE `cv_place_generation_runs`
  ADD PRIMARY KEY (`id_run`),
  ADD KEY `idx_cv_place_generation_runs_status` (`status`,`started_at`);

--
-- Indici per le tabelle `cv_place_metrics`
--
ALTER TABLE `cv_place_metrics`
  ADD PRIMARY KEY (`id_place`),
  ADD KEY `idx_cv_place_metrics_score` (`popularity_score`,`departures_count`,`bookings_count`);

--
-- Indici per le tabelle `cv_place_name_overrides`
--
ALTER TABLE `cv_place_name_overrides`
  ADD PRIMARY KEY (`id_override`),
  ADD UNIQUE KEY `uq_cv_place_name_override_code` (`place_code`),
  ADD KEY `idx_cv_place_name_override_active` (`is_active`,`place_code`);

--
-- Indici per le tabelle `cv_place_stops`
--
ALTER TABLE `cv_place_stops`
  ADD PRIMARY KEY (`id_place_stop`),
  ADD UNIQUE KEY `uq_cv_place_stops_place_stop` (`id_place`,`id_stop`),
  ADD KEY `idx_cv_place_stops_place` (`id_place`,`is_primary`,`priority`),
  ADD KEY `idx_cv_place_stops_stop` (`id_stop`),
  ADD KEY `idx_cv_place_stops_source` (`source`,`is_locked`);

--
-- Indici per le tabelle `cv_place_stop_overrides`
--
ALTER TABLE `cv_place_stop_overrides`
  ADD PRIMARY KEY (`id_override`),
  ADD UNIQUE KEY `uq_cv_place_stop_override` (`id_stop`,`action`),
  ADD KEY `idx_cv_place_stop_override_place` (`forced_place_code`,`is_active`);

--
-- Indici per le tabelle `cv_promotions`
--
ALTER TABLE `cv_promotions`
  ADD PRIMARY KEY (`id_promo`),
  ADD KEY `idx_cv_promotions_active` (`is_active`,`mode`,`visibility`,`valid_from`,`valid_to`,`priority`),
  ADD KEY `idx_cv_promotions_code` (`code`);

--
-- Indici per le tabelle `cv_providers`
--
ALTER TABLE `cv_providers`
  ADD PRIMARY KEY (`id_provider`),
  ADD UNIQUE KEY `uq_cv_providers_code` (`code`);

--
-- Indici per le tabelle `cv_provider_fares`
--
ALTER TABLE `cv_provider_fares`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cv_fares_provider_ext` (`id_provider`,`external_id`),
  ADD KEY `idx_cv_fares_provider_route` (`id_provider`,`from_stop_external_id`,`to_stop_external_id`,`is_active`),
  ADD KEY `idx_cv_fares_provider_run` (`id_provider`,`last_run_id`);

--
-- Indici per le tabelle `cv_provider_lines`
--
ALTER TABLE `cv_provider_lines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cv_lines_provider_ext` (`id_provider`,`external_id`),
  ADD KEY `idx_cv_lines_provider_active` (`id_provider`,`is_active`,`is_visible`),
  ADD KEY `idx_cv_lines_provider_run` (`id_provider`,`last_run_id`);

--
-- Indici per le tabelle `cv_provider_stops`
--
ALTER TABLE `cv_provider_stops`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cv_stops_provider_ext` (`id_provider`,`external_id`),
  ADD KEY `idx_cv_stops_provider_active` (`id_provider`,`is_active`),
  ADD KEY `idx_cv_stops_provider_name` (`id_provider`,`name`),
  ADD KEY `idx_cv_stops_provider_run` (`id_provider`,`last_run_id`);

--
-- Indici per le tabelle `cv_provider_trips`
--
ALTER TABLE `cv_provider_trips`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cv_trips_provider_ext` (`id_provider`,`external_id`),
  ADD KEY `idx_cv_trips_provider_line` (`id_provider`,`line_external_id`,`is_active`),
  ADD KEY `idx_cv_trips_provider_run` (`id_provider`,`last_run_id`);

--
-- Indici per le tabelle `cv_provider_trip_stops`
--
ALTER TABLE `cv_provider_trip_stops`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cv_trip_stops` (`id_provider`,`trip_external_id`,`sequence_no`),
  ADD KEY `idx_cv_trip_stops_trip` (`id_provider`,`trip_external_id`),
  ADD KEY `idx_cv_trip_stops_stop` (`id_provider`,`stop_external_id`),
  ADD KEY `idx_cv_trip_stops_run` (`id_provider`,`last_run_id`);

--
-- Indici per le tabelle `cv_route_seo_pages`
--
ALTER TABLE `cv_route_seo_pages`
  ADD PRIMARY KEY (`id_route_seo_page`),
  ADD UNIQUE KEY `uq_cv_route_seo_slug` (`slug`),
  ADD UNIQUE KEY `uq_cv_route_seo_pair` (`from_ref`,`to_ref`),
  ADD KEY `idx_cv_route_seo_status` (`status`,`updated_at`),
  ADD KEY `idx_cv_route_seo_rank` (`search_count_snapshot`,`last_requested_at`);

--
-- Indici per le tabelle `cv_search_cache`
--
ALTER TABLE `cv_search_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cv_search_cache_key` (`cache_key`),
  ADD KEY `idx_cv_search_cache_exp` (`expires_at`);

--
-- Indici per le tabelle `cv_search_route_stats`
--
ALTER TABLE `cv_search_route_stats`
  ADD PRIMARY KEY (`id_route_stat`),
  ADD UNIQUE KEY `uq_cv_search_route_pair` (`from_ref`,`to_ref`),
  ADD KEY `idx_cv_search_route_rank` (`search_count`,`last_requested_at`),
  ADD KEY `idx_cv_search_route_last` (`last_requested_at`);

--
-- Indici per le tabelle `cv_settings`
--
ALTER TABLE `cv_settings`
  ADD PRIMARY KEY (`id_setting`),
  ADD UNIQUE KEY `uq_cv_settings_key` (`setting_key`);

--
-- Indici per le tabelle `cv_sync_runs`
--
ALTER TABLE `cv_sync_runs`
  ADD PRIMARY KEY (`id_run`),
  ADD KEY `idx_cv_sync_runs_provider_time` (`id_provider`,`started_at`);

--
-- Indici per le tabelle `cv_ticket_recovery_requests`
--
ALTER TABLE `cv_ticket_recovery_requests`
  ADD PRIMARY KEY (`id_request`),
  ADD UNIQUE KEY `uq_cv_ticket_recovery_token` (`token_hash`),
  ADD KEY `idx_cv_ticket_recovery_email` (`email`),
  ADD KEY `idx_cv_ticket_recovery_expires` (`expires_at`),
  ADD KEY `idx_cv_ticket_recovery_session` (`session_key`);

--
-- Indici per le tabelle `linee`
--
ALTER TABLE `linee`
  ADD PRIMARY KEY (`id_linea`),
  ADD KEY `idx_linee_az_stato` (`id_az`,`stato`);

--
-- Indici per le tabelle `mail_sett`
--
ALTER TABLE `mail_sett`
  ADD PRIMARY KEY (`id_sett`);

--
-- Indici per le tabelle `mezzi`
--
ALTER TABLE `mezzi`
  ADD PRIMARY KEY (`id_mz`),
  ADD KEY `idx_mezzi_az_stato` (`id_az`,`stato`),
  ADD KEY `fk_mezzi_mappa` (`id_mztipo`);

--
-- Indici per le tabelle `mezzi_corse`
--
ALTER TABLE `mezzi_corse`
  ADD PRIMARY KEY (`id_mzc`),
  ADD UNIQUE KEY `uq_mezzi_corse_period` (`id_mz`,`id_corsa`,`da`,`a`),
  ADD KEY `idx_mezzi_corse_az` (`id_az`),
  ADD KEY `fk_mezzi_corse_corsa` (`id_corsa`);

--
-- Indici per le tabelle `mezzi_date`
--
ALTER TABLE `mezzi_date`
  ADD PRIMARY KEY (`id_mz_dt`),
  ADD KEY `idx_mezzi_date_lookup` (`id_az`,`data`,`al`),
  ADD KEY `fk_mezzi_date_linea` (`id_linea`);

--
-- Indici per le tabelle `mezzi_mappe`
--
ALTER TABLE `mezzi_mappe`
  ADD PRIMARY KEY (`id_mztipo`),
  ADD KEY `idx_mezzi_mappe_az` (`id_az`);

--
-- Indici per le tabelle `payment_errors`
--
ALTER TABLE `payment_errors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment_errors_time` (`event_time`),
  ADD KEY `idx_payment_errors_order` (`order_id`),
  ADD KEY `fk_payment_errors_azienda` (`id_az`);

--
-- Indici per le tabelle `prz_bag`
--
ALTER TABLE `prz_bag`
  ADD PRIMARY KEY (`id_przbg`),
  ADD KEY `idx_prz_bag_lookup` (`id_az`,`tipobg`,`da`,`a`,`stato`);

--
-- Indici per le tabelle `prz_date`
--
ALTER TABLE `prz_date`
  ADD PRIMARY KEY (`id_przdt`),
  ADD KEY `idx_prz_date_lookup` (`id_az`,`da`,`a`,`stato`);

--
-- Indici per le tabelle `pst_date`
--
ALTER TABLE `pst_date`
  ADD PRIMARY KEY (`id_pst`),
  ADD KEY `idx_pst_date_lookup` (`id_az`,`da`,`a`,`stato`);

--
-- Indici per le tabelle `pst_prz`
--
ALTER TABLE `pst_prz`
  ADD PRIMARY KEY (`id_pstprz`),
  ADD UNIQUE KEY `uq_pst_prz_tipo` (`id_az`,`tipo`);

--
-- Indici per le tabelle `regole`
--
ALTER TABLE `regole`
  ADD PRIMARY KEY (`id_r`),
  ADD KEY `idx_regole_lookup` (`id_az`,`id_sott1`,`id_sott2`,`id_linea`);

--
-- Indici per le tabelle `regole_corse`
--
ALTER TABLE `regole_corse`
  ADD PRIMARY KEY (`id_rc`),
  ADD KEY `idx_regole_corse_lookup` (`id_az`,`id_sott`,`da`,`a`),
  ADD KEY `fk_regole_corse_stop` (`id_sott`);

--
-- Indici per le tabelle `regole_linee`
--
ALTER TABLE `regole_linee`
  ADD PRIMARY KEY (`id_rl`),
  ADD KEY `idx_regole_linee_lookup` (`id_az`,`id_linea`,`da`,`a`),
  ADD KEY `fk_regole_linee_linea` (`id_linea`);

--
-- Indici per le tabelle `regole_tratta`
--
ALTER TABLE `regole_tratta`
  ADD PRIMARY KEY (`id_rtr`),
  ADD KEY `idx_regole_tratta_lookup` (`id_az`,`id_sott1`,`id_sott2`,`da`,`a`),
  ADD KEY `fk_regole_tratta_sott1` (`id_sott1`),
  ADD KEY `fk_regole_tratta_sott2` (`id_sott2`);

--
-- Indici per le tabelle `sconti`
--
ALTER TABLE `sconti`
  ADD PRIMARY KEY (`id_cod`),
  ADD KEY `idx_sconti_az_time` (`id_az`,`partenza`,`scadenza`),
  ADD KEY `idx_sconti_codice` (`codice`);

--
-- Indici per le tabelle `tratte_sottoc`
--
ALTER TABLE `tratte_sottoc`
  ADD PRIMARY KEY (`id_sott`),
  ADD KEY `idx_tratte_az_stato` (`id_az`,`stato`),
  ADD KEY `idx_tratte_nome` (`nome`),
  ADD KEY `idx_tratte_latlon` (`lat`,`lon`);

--
-- Indici per le tabelle `tratte_sottoc_tratte`
--
ALTER TABLE `tratte_sottoc_tratte`
  ADD PRIMARY KEY (`id_tst`),
  ADD UNIQUE KEY `uq_tratta_prezzo` (`id_az`,`id_sott1`,`id_sott2`),
  ADD KEY `idx_tratta_prezzo_stato` (`id_az`,`stato`),
  ADD KEY `fk_tst_sott1` (`id_sott1`),
  ADD KEY `fk_tst_sott2` (`id_sott2`);

--
-- Indici per le tabelle `viaggiatori`
--
ALTER TABLE `viaggiatori`
  ADD PRIMARY KEY (`id_vg`),
  ADD KEY `idx_viaggiatori_email` (`email`),
  ADD KEY `idx_viaggiatori_stato` (`stato`);

--
-- Indici per le tabelle `viaggiatori_temp`
--
ALTER TABLE `viaggiatori_temp`
  ADD PRIMARY KEY (`id_vgt`),
  ADD KEY `idx_viaggiatori_temp_email` (`email`);

--
-- AUTO_INCREMENT per le tabelle scaricate
--

--
-- AUTO_INCREMENT per la tabella `abbcarn_lista`
--
ALTER TABLE `abbcarn_lista`
  MODIFY `id_codabbcarn_l` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `abbcarn_utenti`
--
ALTER TABLE `abbcarn_utenti`
  MODIFY `id_codabbcarn_u` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `abbcarn_utenti_reg`
--
ALTER TABLE `abbcarn_utenti_reg`
  MODIFY `id_abbcarn_reg` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `aziende`
--
ALTER TABLE `aziende`
  MODIFY `id_az` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `aziende_comm`
--
ALTER TABLE `aziende_comm`
  MODIFY `id_azcomm` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `aziende_pag2`
--
ALTER TABLE `aziende_pag2`
  MODIFY `az_p` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `biglietti`
--
ALTER TABLE `biglietti`
  MODIFY `id_bg` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `biglietti_log`
--
ALTER TABLE `biglietti_log`
  MODIFY `id_b_log` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `biglietti_reg`
--
ALTER TABLE `biglietti_reg`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `corse`
--
ALTER TABLE `corse`
  MODIFY `id_corsa` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `corse_fermate`
--
ALTER TABLE `corse_fermate`
  MODIFY `id_corse_f` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_api_logs`
--
ALTER TABLE `cv_api_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_assistant_conversations`
--
ALTER TABLE `cv_assistant_conversations`
  MODIFY `id_conversation` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_assistant_feedback`
--
ALTER TABLE `cv_assistant_feedback`
  MODIFY `id_feedback` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_assistant_knowledge`
--
ALTER TABLE `cv_assistant_knowledge`
  MODIFY `id_knowledge` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_assistant_messages`
--
ALTER TABLE `cv_assistant_messages`
  MODIFY `id_message` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_assistant_settings`
--
ALTER TABLE `cv_assistant_settings`
  MODIFY `id_sett` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_assistant_support_messages`
--
ALTER TABLE `cv_assistant_support_messages`
  MODIFY `id_ticket_message` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_assistant_support_tickets`
--
ALTER TABLE `cv_assistant_support_tickets`
  MODIFY `id_ticket` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_backend_users`
--
ALTER TABLE `cv_backend_users`
  MODIFY `id_user` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_backend_user_providers`
--
ALTER TABLE `cv_backend_user_providers`
  MODIFY `id_user_provider` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_blog_posts`
--
ALTER TABLE `cv_blog_posts`
  MODIFY `id_blog_post` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_email_verifications`
--
ALTER TABLE `cv_email_verifications`
  MODIFY `id_verification` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_error_log`
--
ALTER TABLE `cv_error_log`
  MODIFY `id_error_log` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_home_provider_featured_routes`
--
ALTER TABLE `cv_home_provider_featured_routes`
  MODIFY `id_featured_route` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_idempotency_keys`
--
ALTER TABLE `cv_idempotency_keys`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_newsletter_campaigns`
--
ALTER TABLE `cv_newsletter_campaigns`
  MODIFY `id_campaign` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_newsletter_guest_subscriptions`
--
ALTER TABLE `cv_newsletter_guest_subscriptions`
  MODIFY `id_guest_subscription` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_newsletter_guest_verifications`
--
ALTER TABLE `cv_newsletter_guest_verifications`
  MODIFY `id_news_verify` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_newsletter_subscriptions`
--
ALTER TABLE `cv_newsletter_subscriptions`
  MODIFY `id_subscription` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_orders`
--
ALTER TABLE `cv_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_order_legs`
--
ALTER TABLE `cv_order_legs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_password_resets`
--
ALTER TABLE `cv_password_resets`
  MODIFY `id_reset` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_payment_settings`
--
ALTER TABLE `cv_payment_settings`
  MODIFY `id_setting` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_payment_splits`
--
ALTER TABLE `cv_payment_splits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_payment_transactions`
--
ALTER TABLE `cv_payment_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_places`
--
ALTER TABLE `cv_places`
  MODIFY `id_place` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_place_aliases`
--
ALTER TABLE `cv_place_aliases`
  MODIFY `id_alias` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_place_generation_runs`
--
ALTER TABLE `cv_place_generation_runs`
  MODIFY `id_run` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_place_name_overrides`
--
ALTER TABLE `cv_place_name_overrides`
  MODIFY `id_override` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_place_stops`
--
ALTER TABLE `cv_place_stops`
  MODIFY `id_place_stop` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_place_stop_overrides`
--
ALTER TABLE `cv_place_stop_overrides`
  MODIFY `id_override` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_promotions`
--
ALTER TABLE `cv_promotions`
  MODIFY `id_promo` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_providers`
--
ALTER TABLE `cv_providers`
  MODIFY `id_provider` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_provider_fares`
--
ALTER TABLE `cv_provider_fares`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_provider_lines`
--
ALTER TABLE `cv_provider_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_provider_stops`
--
ALTER TABLE `cv_provider_stops`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_provider_trips`
--
ALTER TABLE `cv_provider_trips`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_provider_trip_stops`
--
ALTER TABLE `cv_provider_trip_stops`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_route_seo_pages`
--
ALTER TABLE `cv_route_seo_pages`
  MODIFY `id_route_seo_page` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_search_cache`
--
ALTER TABLE `cv_search_cache`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_search_route_stats`
--
ALTER TABLE `cv_search_route_stats`
  MODIFY `id_route_stat` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_settings`
--
ALTER TABLE `cv_settings`
  MODIFY `id_setting` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_sync_runs`
--
ALTER TABLE `cv_sync_runs`
  MODIFY `id_run` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `cv_ticket_recovery_requests`
--
ALTER TABLE `cv_ticket_recovery_requests`
  MODIFY `id_request` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `linee`
--
ALTER TABLE `linee`
  MODIFY `id_linea` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `mail_sett`
--
ALTER TABLE `mail_sett`
  MODIFY `id_sett` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `mezzi`
--
ALTER TABLE `mezzi`
  MODIFY `id_mz` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `mezzi_corse`
--
ALTER TABLE `mezzi_corse`
  MODIFY `id_mzc` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `mezzi_date`
--
ALTER TABLE `mezzi_date`
  MODIFY `id_mz_dt` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `mezzi_mappe`
--
ALTER TABLE `mezzi_mappe`
  MODIFY `id_mztipo` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `payment_errors`
--
ALTER TABLE `payment_errors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `prz_bag`
--
ALTER TABLE `prz_bag`
  MODIFY `id_przbg` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `prz_date`
--
ALTER TABLE `prz_date`
  MODIFY `id_przdt` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pst_date`
--
ALTER TABLE `pst_date`
  MODIFY `id_pst` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `pst_prz`
--
ALTER TABLE `pst_prz`
  MODIFY `id_pstprz` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `regole`
--
ALTER TABLE `regole`
  MODIFY `id_r` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `regole_corse`
--
ALTER TABLE `regole_corse`
  MODIFY `id_rc` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `regole_linee`
--
ALTER TABLE `regole_linee`
  MODIFY `id_rl` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `regole_tratta`
--
ALTER TABLE `regole_tratta`
  MODIFY `id_rtr` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `sconti`
--
ALTER TABLE `sconti`
  MODIFY `id_cod` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `tratte_sottoc`
--
ALTER TABLE `tratte_sottoc`
  MODIFY `id_sott` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `tratte_sottoc_tratte`
--
ALTER TABLE `tratte_sottoc_tratte`
  MODIFY `id_tst` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `viaggiatori`
--
ALTER TABLE `viaggiatori`
  MODIFY `id_vg` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT per la tabella `viaggiatori_temp`
--
ALTER TABLE `viaggiatori_temp`
  MODIFY `id_vgt` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Limiti per le tabelle scaricate
--

--
-- Limiti per la tabella `abbcarn_lista`
--
ALTER TABLE `abbcarn_lista`
  ADD CONSTRAINT `fk_abbcarn_lista_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `abbcarn_utenti`
--
ALTER TABLE `abbcarn_utenti`
  ADD CONSTRAINT `fk_abbcarn_utenti_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_abbcarn_utenti_lista` FOREIGN KEY (`id_codabbcarn_l`) REFERENCES `abbcarn_lista` (`id_codabbcarn_l`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_abbcarn_utenti_vg` FOREIGN KEY (`id_vg`) REFERENCES `viaggiatori` (`id_vg`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `abbcarn_utenti_reg`
--
ALTER TABLE `abbcarn_utenti_reg`
  ADD CONSTRAINT `fk_abbcarn_utenti_reg_u` FOREIGN KEY (`id_codabbcarn_u`) REFERENCES `abbcarn_utenti` (`id_codabbcarn_u`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `aziende_comm`
--
ALTER TABLE `aziende_comm`
  ADD CONSTRAINT `fk_aziende_comm_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `aziende_pag2`
--
ALTER TABLE `aziende_pag2`
  ADD CONSTRAINT `fk_aziende_pag2_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `biglietti`
--
ALTER TABLE `biglietti`
  ADD CONSTRAINT `fk_biglietti_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_biglietti_corsa` FOREIGN KEY (`id_corsa`) REFERENCES `corse` (`id_corsa`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_biglietti_linea` FOREIGN KEY (`id_linea`) REFERENCES `linee` (`id_linea`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_biglietti_sott1` FOREIGN KEY (`id_sott1`) REFERENCES `tratte_sottoc` (`id_sott`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_biglietti_sott2` FOREIGN KEY (`id_sott2`) REFERENCES `tratte_sottoc` (`id_sott`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_biglietti_vg` FOREIGN KEY (`id_vg`) REFERENCES `viaggiatori` (`id_vg`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_biglietti_vgt` FOREIGN KEY (`id_vgt`) REFERENCES `viaggiatori_temp` (`id_vgt`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Limiti per la tabella `biglietti_log`
--
ALTER TABLE `biglietti_log`
  ADD CONSTRAINT `fk_biglietti_log_bg` FOREIGN KEY (`id_bg`) REFERENCES `biglietti` (`id_bg`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `biglietti_reg`
--
ALTER TABLE `biglietti_reg`
  ADD CONSTRAINT `fk_biglietti_reg_bg` FOREIGN KEY (`id_bg`) REFERENCES `biglietti` (`id_bg`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `corse`
--
ALTER TABLE `corse`
  ADD CONSTRAINT `fk_corse_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_corse_linea` FOREIGN KEY (`id_linea`) REFERENCES `linee` (`id_linea`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `corse_fermate`
--
ALTER TABLE `corse_fermate`
  ADD CONSTRAINT `fk_corse_fermate_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_corse_fermate_corsa` FOREIGN KEY (`id_corsa`) REFERENCES `corse` (`id_corsa`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_corse_fermate_stop` FOREIGN KEY (`id_sott`) REFERENCES `tratte_sottoc` (`id_sott`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_api_logs`
--
ALTER TABLE `cv_api_logs`
  ADD CONSTRAINT `fk_cv_api_logs_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_backend_user_providers`
--
ALTER TABLE `cv_backend_user_providers`
  ADD CONSTRAINT `fk_cv_backend_user_provider_user` FOREIGN KEY (`id_user`) REFERENCES `cv_backend_users` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_idempotency_keys`
--
ALTER TABLE `cv_idempotency_keys`
  ADD CONSTRAINT `fk_cv_idem_order` FOREIGN KEY (`order_id`) REFERENCES `cv_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_order_legs`
--
ALTER TABLE `cv_order_legs`
  ADD CONSTRAINT `fk_cv_order_legs_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cv_order_legs_order` FOREIGN KEY (`order_id`) REFERENCES `cv_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_payment_splits`
--
ALTER TABLE `cv_payment_splits`
  ADD CONSTRAINT `fk_cv_splits_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cv_splits_tx` FOREIGN KEY (`payment_tx_id`) REFERENCES `cv_payment_transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_payment_transactions`
--
ALTER TABLE `cv_payment_transactions`
  ADD CONSTRAINT `fk_cv_pay_tx_order` FOREIGN KEY (`order_id`) REFERENCES `cv_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_places`
--
ALTER TABLE `cv_places`
  ADD CONSTRAINT `fk_cv_places_parent` FOREIGN KEY (`parent_id_place`) REFERENCES `cv_places` (`id_place`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_place_aliases`
--
ALTER TABLE `cv_place_aliases`
  ADD CONSTRAINT `fk_cv_place_aliases_place` FOREIGN KEY (`id_place`) REFERENCES `cv_places` (`id_place`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_place_metrics`
--
ALTER TABLE `cv_place_metrics`
  ADD CONSTRAINT `fk_cv_place_metrics_place` FOREIGN KEY (`id_place`) REFERENCES `cv_places` (`id_place`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_place_stops`
--
ALTER TABLE `cv_place_stops`
  ADD CONSTRAINT `fk_cv_place_stops_place` FOREIGN KEY (`id_place`) REFERENCES `cv_places` (`id_place`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cv_place_stops_stop` FOREIGN KEY (`id_stop`) REFERENCES `cv_provider_stops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_place_stop_overrides`
--
ALTER TABLE `cv_place_stop_overrides`
  ADD CONSTRAINT `fk_cv_place_stop_override_stop` FOREIGN KEY (`id_stop`) REFERENCES `cv_provider_stops` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_provider_fares`
--
ALTER TABLE `cv_provider_fares`
  ADD CONSTRAINT `fk_cv_fares_provider` FOREIGN KEY (`id_provider`) REFERENCES `cv_providers` (`id_provider`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_provider_lines`
--
ALTER TABLE `cv_provider_lines`
  ADD CONSTRAINT `fk_cv_lines_provider` FOREIGN KEY (`id_provider`) REFERENCES `cv_providers` (`id_provider`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_provider_stops`
--
ALTER TABLE `cv_provider_stops`
  ADD CONSTRAINT `fk_cv_stops_provider` FOREIGN KEY (`id_provider`) REFERENCES `cv_providers` (`id_provider`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_provider_trips`
--
ALTER TABLE `cv_provider_trips`
  ADD CONSTRAINT `fk_cv_trips_provider` FOREIGN KEY (`id_provider`) REFERENCES `cv_providers` (`id_provider`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_provider_trip_stops`
--
ALTER TABLE `cv_provider_trip_stops`
  ADD CONSTRAINT `fk_cv_trip_stops_provider` FOREIGN KEY (`id_provider`) REFERENCES `cv_providers` (`id_provider`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `cv_sync_runs`
--
ALTER TABLE `cv_sync_runs`
  ADD CONSTRAINT `fk_cv_sync_runs_provider` FOREIGN KEY (`id_provider`) REFERENCES `cv_providers` (`id_provider`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `linee`
--
ALTER TABLE `linee`
  ADD CONSTRAINT `fk_linee_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `mezzi`
--
ALTER TABLE `mezzi`
  ADD CONSTRAINT `fk_mezzi_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mezzi_mappa` FOREIGN KEY (`id_mztipo`) REFERENCES `mezzi_mappe` (`id_mztipo`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Limiti per la tabella `mezzi_corse`
--
ALTER TABLE `mezzi_corse`
  ADD CONSTRAINT `fk_mezzi_corse_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mezzi_corse_corsa` FOREIGN KEY (`id_corsa`) REFERENCES `corse` (`id_corsa`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mezzi_corse_mz` FOREIGN KEY (`id_mz`) REFERENCES `mezzi` (`id_mz`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `mezzi_date`
--
ALTER TABLE `mezzi_date`
  ADD CONSTRAINT `fk_mezzi_date_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mezzi_date_linea` FOREIGN KEY (`id_linea`) REFERENCES `linee` (`id_linea`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Limiti per la tabella `mezzi_mappe`
--
ALTER TABLE `mezzi_mappe`
  ADD CONSTRAINT `fk_mezzi_mappe_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `payment_errors`
--
ALTER TABLE `payment_errors`
  ADD CONSTRAINT `fk_payment_errors_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Limiti per la tabella `prz_bag`
--
ALTER TABLE `prz_bag`
  ADD CONSTRAINT `fk_prz_bag_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `prz_date`
--
ALTER TABLE `prz_date`
  ADD CONSTRAINT `fk_prz_date_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `pst_date`
--
ALTER TABLE `pst_date`
  ADD CONSTRAINT `fk_pst_date_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `pst_prz`
--
ALTER TABLE `pst_prz`
  ADD CONSTRAINT `fk_pst_prz_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `regole`
--
ALTER TABLE `regole`
  ADD CONSTRAINT `fk_regole_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `regole_corse`
--
ALTER TABLE `regole_corse`
  ADD CONSTRAINT `fk_regole_corse_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_regole_corse_stop` FOREIGN KEY (`id_sott`) REFERENCES `tratte_sottoc` (`id_sott`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `regole_linee`
--
ALTER TABLE `regole_linee`
  ADD CONSTRAINT `fk_regole_linee_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_regole_linee_linea` FOREIGN KEY (`id_linea`) REFERENCES `linee` (`id_linea`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `regole_tratta`
--
ALTER TABLE `regole_tratta`
  ADD CONSTRAINT `fk_regole_tratta_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_regole_tratta_sott1` FOREIGN KEY (`id_sott1`) REFERENCES `tratte_sottoc` (`id_sott`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_regole_tratta_sott2` FOREIGN KEY (`id_sott2`) REFERENCES `tratte_sottoc` (`id_sott`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `sconti`
--
ALTER TABLE `sconti`
  ADD CONSTRAINT `fk_sconti_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `tratte_sottoc`
--
ALTER TABLE `tratte_sottoc`
  ADD CONSTRAINT `fk_tratte_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limiti per la tabella `tratte_sottoc_tratte`
--
ALTER TABLE `tratte_sottoc_tratte`
  ADD CONSTRAINT `fk_tst_azienda` FOREIGN KEY (`id_az`) REFERENCES `aziende` (`id_az`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tst_sott1` FOREIGN KEY (`id_sott1`) REFERENCES `tratte_sottoc` (`id_sott`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tst_sott2` FOREIGN KEY (`id_sott2`) REFERENCES `tratte_sottoc` (`id_sott`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
