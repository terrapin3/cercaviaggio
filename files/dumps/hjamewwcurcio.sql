-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: hjamewwcurcio.mysql.db
-- Generation Time: Mar 10, 2026 at 03:59 PM
-- Server version: 8.0.45-36
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hjamewwcurcio`
--

-- --------------------------------------------------------

--
-- Table structure for table `abbcarn_editor`
--

CREATE TABLE `abbcarn_editor` (
  `id_labbcrn` int NOT NULL,
  `id_codabbcarn_l` int NOT NULL,
  `primo` int DEFAULT '0',
  `text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `info` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` datetime(2) DEFAULT NULL,
  `stato` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `abbcarn_lista`
--

CREATE TABLE `abbcarn_lista` (
  `id_codabbcarn_l` int NOT NULL,
  `nome` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '-',
  `codice` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo` int DEFAULT '0',
  `prezzo_scontato` float DEFAULT '0',
  `prezzo` float DEFAULT '0',
  `partenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `scadenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `giorni_sett` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '-',
  `n_viaggi` int DEFAULT '0',
  `durata` int DEFAULT '1',
  `linee` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_corsa` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `disponibilita` int DEFAULT '5000',
  `stato` int DEFAULT '1',
  `id_az` int DEFAULT '1',
  `visibile` int NOT NULL DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `abbcarn_utenti`
--

CREATE TABLE `abbcarn_utenti` (
  `id_codabbcarn_u` int NOT NULL,
  `id_codabbcarn_l` int NOT NULL,
  `id_vg` int NOT NULL,
  `codice_ac` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `codice_u` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `tipo` int DEFAULT '0',
  `prezzo_scontato` float NOT NULL,
  `giorni_sett` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `n_viaggi` int DEFAULT NULL,
  `id_corsa` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `linee` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `partenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `scadenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `prezzo` float NOT NULL,
  `pagato` int DEFAULT '0',
  `txn_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '-',
  `tipo_pg` int DEFAULT '0',
  `acquistato` datetime NOT NULL,
  `stato` int DEFAULT '1',
  `id_az` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `abbcarn_utenti_reg`
--

CREATE TABLE `abbcarn_utenti_reg` (
  `id_abbcarn_reg` int NOT NULL,
  `id_codabbcarn_u` int NOT NULL,
  `id_codabbcarn_l` int NOT NULL,
  `id_vg` int NOT NULL,
  `codice_ac` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `codice_u` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `tipo` int DEFAULT '0',
  `prezzo_scontato` float NOT NULL,
  `giorni_sett` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `n_viaggi` int DEFAULT NULL,
  `id_corsa` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `linee` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `partenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `scadenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `prezzo` float NOT NULL,
  `pagato` int DEFAULT '0',
  `txn_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '-',
  `tipo_pg` int DEFAULT '0',
  `acquistato` datetime NOT NULL,
  `stato` int DEFAULT '1',
  `id_az` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assicuratori`
--

CREATE TABLE `assicuratori` (
  `id_ass` int NOT NULL,
  `nome_ag` varchar(50) NOT NULL,
  `citta` varchar(255) DEFAULT '-',
  `picf` varchar(50) DEFAULT '-',
  `email` varchar(100) NOT NULL,
  `tel` varchar(20) NOT NULL,
  `data` date DEFAULT '1970-01-01',
  `stato` int DEFAULT '1',
  `prezzo_assic` float DEFAULT '5',
  `prezzo_bag` float DEFAULT '3'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `aziende`
--

CREATE TABLE `aziende` (
  `id_az` int NOT NULL,
  `nome` varchar(150) NOT NULL,
  `pi` varchar(150) DEFAULT '-',
  `ind` varchar(255) NOT NULL,
  `tel` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '-',
  `recapiti` varchar(255) DEFAULT '-',
  `email_pg` varchar(100) DEFAULT '-',
  `auth_tk` varchar(100) DEFAULT '-',
  `prof` int DEFAULT '1',
  `stato` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `aziende_comm`
--

CREATE TABLE `aziende_comm` (
  `id_azcomm` int NOT NULL,
  `id_az` int NOT NULL,
  `comm_app` int DEFAULT '0',
  `comm_web` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `aziende_pag`
--

CREATE TABLE `aziende_pag` (
  `az_p` int NOT NULL,
  `id_az` int NOT NULL,
  `email_pg` varchar(100) DEFAULT '-',
  `auth_tk` varchar(100) DEFAULT '-',
  `ppredirect` int DEFAULT '1',
  `ppcheckout` int DEFAULT '0',
  `ppcarta` int DEFAULT '0',
  `ut_api` varchar(100) DEFAULT '-',
  `pass_api` varchar(100) DEFAULT '-',
  `firma_api` varchar(100) DEFAULT '-',
  `accountid` varchar(255) DEFAULT '-',
  `accountid0` varchar(255) DEFAULT '',
  `clientid` varchar(255) DEFAULT '-',
  `clientid0` varchar(255) DEFAULT '-',
  `secret` varchar(255) DEFAULT '-',
  `secret0` varchar(255) DEFAULT '',
  `pk` varchar(255) DEFAULT '-',
  `sk` varchar(255) DEFAULT '-',
  `wh` varchar(255) DEFAULT '-',
  `tipo` int NOT NULL DEFAULT '1',
  `richiesta` datetime DEFAULT NULL,
  `richiesta0` datetime DEFAULT NULL,
  `token` varchar(255) DEFAULT NULL,
  `token0` varchar(255) DEFAULT NULL,
  `stato` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `aziende_pag2`
--

CREATE TABLE `aziende_pag2` (
  `az_p` int NOT NULL,
  `id_az` int NOT NULL,
  `environment` enum('production','sandbox','','') NOT NULL,
  `email_pg` varchar(100) DEFAULT '-',
  `auth_tk` varchar(100) DEFAULT '-',
  `ppredirect` int DEFAULT '1',
  `ppcheckout` int DEFAULT '0',
  `ppcarta` int DEFAULT '0',
  `ut_api` varchar(100) DEFAULT '-',
  `pass_api` varchar(100) DEFAULT '-',
  `firma_api` varchar(100) DEFAULT '-',
  `accountid` varchar(255) DEFAULT '-',
  `clientid` varchar(255) DEFAULT '-',
  `secret` varchar(255) DEFAULT '-',
  `pk` varchar(255) DEFAULT '-',
  `sk` varchar(255) DEFAULT '-',
  `wh` varchar(255) DEFAULT '-',
  `tipo` int NOT NULL DEFAULT '1',
  `richiesta` datetime DEFAULT NULL,
  `token` varchar(255) DEFAULT NULL,
  `stato` int DEFAULT '1',
  `stato_app` int DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `bagagli`
--

CREATE TABLE `bagagli` (
  `id_pac` int NOT NULL,
  `id_linea` int NOT NULL,
  `id_corsa` int NOT NULL,
  `id_sott` int NOT NULL,
  `id_sott2` int NOT NULL DEFAULT '0',
  `quantita` int NOT NULL DEFAULT '1',
  `prezzo` float NOT NULL DEFAULT '0',
  `pos` int DEFAULT '0',
  `bus` int NOT NULL DEFAULT '1',
  `dimensione` varchar(20) DEFAULT '0',
  `nome` varchar(100) NOT NULL DEFAULT '-',
  `cognome` varchar(100) NOT NULL DEFAULT '-',
  `telefono` varchar(20) NOT NULL DEFAULT '0',
  `note` varchar(255) NOT NULL DEFAULT '-',
  `data` date NOT NULL,
  `prenotato` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `stato` int NOT NULL DEFAULT '1',
  `convertito` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `biglietti`
--

CREATE TABLE `biglietti` (
  `id_bg` int NOT NULL,
  `id_ut` int DEFAULT '0',
  `id_mz` int DEFAULT '0',
  `id_linea` int DEFAULT '0',
  `id_corsa` int DEFAULT '0',
  `id_r` int DEFAULT '0',
  `id_sott1` int NOT NULL,
  `id_sott2` int NOT NULL,
  `prezzo` float NOT NULL,
  `pen` int DEFAULT '0',
  `camb` int DEFAULT '0',
  `sospeso` int DEFAULT '0',
  `rid` int NOT NULL,
  `pacco` int DEFAULT '0',
  `pacco_a` int DEFAULT '0',
  `prz_pacco` float DEFAULT '0',
  `prz_pacco_a` float DEFAULT '0',
  `prenotaz` int DEFAULT '0',
  `codice` varchar(12) DEFAULT '0',
  `codice_camb` varchar(12) NOT NULL DEFAULT '0',
  `transaction_id` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '0',
  `posto` int DEFAULT '0',
  `prz_posto` float DEFAULT '0',
  `prz_comm` float DEFAULT '0',
  `note` varchar(255) DEFAULT ' ',
  `pos` int NOT NULL,
  `id_vg` int DEFAULT '0',
  `id_vgt` int DEFAULT '0',
  `id_cod` int DEFAULT '0',
  `id_codabbcarn_u` int DEFAULT '0',
  `stato` int NOT NULL,
  `rimborsato` int NOT NULL DEFAULT '0',
  `controllato` int DEFAULT '0',
  `pagato` int DEFAULT '0',
  `stampato` int DEFAULT '0',
  `mz_dt` int DEFAULT '0',
  `type` int DEFAULT '1',
  `app` int DEFAULT '0',
  `txn_id` varchar(100) NOT NULL DEFAULT '0',
  `attesa` int DEFAULT '0',
  `data` datetime DEFAULT NULL,
  `data2` datetime DEFAULT NULL,
  `acquistato` datetime NOT NULL,
  `data_sos` datetime DEFAULT '1970-01-01 00:00:00',
  `data_attesa` datetime DEFAULT '1970-01-01 00:00:00',
  `visto` datetime NOT NULL DEFAULT '1970-01-01 00:00:00'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `biglietti_ipn`
--

CREATE TABLE `biglietti_ipn` (
  `id_ipn` int NOT NULL,
  `prd` int DEFAULT '1',
  `tipo_pag` int DEFAULT '1',
  `txn_id` varchar(100) DEFAULT '-',
  `item_number` varchar(2000) DEFAULT '-',
  `transaction_id` varchar(150) DEFAULT '0',
  `data` datetime DEFAULT '1970-01-01 00:00:00',
  `stato` varchar(100) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `biglietti_ipn_logs`
--

CREATE TABLE `biglietti_ipn_logs` (
  `id` int UNSIGNED NOT NULL,
  `descrizione` text NOT NULL,
  `data` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `biglietti_log`
--

CREATE TABLE `biglietti_log` (
  `id_bg` int NOT NULL,
  `id_b_log` int NOT NULL,
  `id_ut` int DEFAULT '0',
  `id_mz` int DEFAULT '0',
  `id_linea` int DEFAULT '0',
  `id_corsa` int DEFAULT '0',
  `id_r` int DEFAULT '0',
  `id_sott1` int NOT NULL,
  `id_sott2` int NOT NULL,
  `prezzo` float NOT NULL,
  `pen` int DEFAULT '0',
  `camb` int DEFAULT '0',
  `sospeso` int DEFAULT '0',
  `rid` int NOT NULL,
  `pacco` int DEFAULT '0',
  `pacco_a` int DEFAULT '0',
  `prz_pacco` float DEFAULT '0',
  `prz_pacco_a` float DEFAULT '0',
  `prenotaz` int DEFAULT '0',
  `codice` varchar(12) DEFAULT '0',
  `codice_camb` varchar(12) NOT NULL DEFAULT '0',
  `transaction_id` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '0',
  `posto` int DEFAULT '0',
  `prz_posto` float DEFAULT '0',
  `prz_comm` float DEFAULT '0',
  `note` varchar(255) DEFAULT ' ',
  `pos` int NOT NULL,
  `id_vg` int DEFAULT '0',
  `id_vgt` int DEFAULT '0',
  `id_cod` int DEFAULT '0',
  `id_codabbcarn_u` int DEFAULT '0',
  `stato` int NOT NULL,
  `rimborsato` int NOT NULL DEFAULT '0',
  `controllato` int DEFAULT '0',
  `pagato` int DEFAULT '0',
  `stampato` int DEFAULT '0',
  `mz_dt` int DEFAULT '0',
  `type` int DEFAULT '1',
  `app` int DEFAULT '0',
  `txn_id` varchar(30) NOT NULL DEFAULT '0',
  `attesa` int DEFAULT '0',
  `data` datetime DEFAULT NULL,
  `data2` datetime DEFAULT NULL,
  `acquistato` datetime NOT NULL,
  `data_sos` datetime DEFAULT '1970-01-01 00:00:00',
  `data_attesa` datetime DEFAULT '1970-01-01 00:00:00',
  `visto` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `operazione` datetime DEFAULT NULL,
  `id_utop` int DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `biglietti_log_orari`
--

CREATE TABLE `biglietti_log_orari` (
  `id_bglo` int NOT NULL,
  `id_bg` int NOT NULL,
  `id_corsa` int NOT NULL,
  `id_sott1` int NOT NULL,
  `id_sott2` int NOT NULL,
  `data_prec` datetime NOT NULL,
  `data2_prec` datetime NOT NULL,
  `data_new` datetime NOT NULL,
  `data2_new` datetime NOT NULL,
  `data_operazione` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `biglietti_reg`
--

CREATE TABLE `biglietti_reg` (
  `id` int NOT NULL,
  `id_bg` int NOT NULL,
  `id_ut` int DEFAULT '0',
  `id_mz` int DEFAULT '0',
  `id_linea` int DEFAULT '0',
  `id_corsa` int DEFAULT '0',
  `id_r` int DEFAULT '0',
  `id_sott1` int NOT NULL,
  `id_sott2` int NOT NULL,
  `prezzo` float NOT NULL,
  `pen` int DEFAULT '0',
  `camb` int DEFAULT '0',
  `sospeso` int DEFAULT '0',
  `rid` int NOT NULL,
  `pacco` int DEFAULT '0',
  `pacco_a` int DEFAULT '0',
  `prz_pacco` float DEFAULT '0',
  `prz_pacco_a` float DEFAULT '0',
  `prenotaz` int DEFAULT '0',
  `codice` varchar(12) DEFAULT '0',
  `codice_camb` varchar(12) NOT NULL DEFAULT '0',
  `transaction_id` varchar(150) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '0',
  `posto` int DEFAULT '0',
  `prz_posto` float DEFAULT '0',
  `prz_comm` float DEFAULT '0',
  `note` varchar(255) DEFAULT ' ',
  `pos` int NOT NULL,
  `id_vg` int DEFAULT '0',
  `id_vgt` int DEFAULT '0',
  `id_cod` int DEFAULT '0',
  `id_codabbcarn_u` int DEFAULT '0',
  `stato` int NOT NULL,
  `rimborsato` int NOT NULL DEFAULT '0',
  `controllato` int DEFAULT '0',
  `pagato` int DEFAULT '0',
  `stampato` int DEFAULT '0',
  `mz_dt` int DEFAULT '0',
  `type` int DEFAULT '1',
  `app` int DEFAULT '0',
  `txn_id` varchar(100) NOT NULL DEFAULT '0',
  `attesa` int DEFAULT '0',
  `data` datetime DEFAULT NULL,
  `data2` datetime DEFAULT NULL,
  `acquistato` datetime NOT NULL,
  `data_sos` datetime DEFAULT '1970-01-01 00:00:00',
  `data_attesa` datetime DEFAULT '1970-01-01 00:00:00',
  `visto` datetime NOT NULL DEFAULT '1970-01-01 00:00:00'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `biglietti_rit`
--

CREATE TABLE `biglietti_rit` (
  `id_r` int NOT NULL,
  `txt` text,
  `id_bg` longtext,
  `data` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `cadenza`
--

CREATE TABLE `cadenza` (
  `id_cad` int NOT NULL,
  `nome` varchar(50) NOT NULL,
  `stato` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `corse`
--

CREATE TABLE `corse` (
  `id_corsa` int NOT NULL,
  `nome` varchar(200) NOT NULL,
  `id_linea` varchar(11) NOT NULL,
  `tempo_acquisto` int DEFAULT '30',
  `gruppo` varchar(10) NOT NULL DEFAULT 'a_1',
  `recapiti` varchar(30) DEFAULT '-',
  `transitoria` int DEFAULT '0',
  `stato` int DEFAULT '1',
  `visualizzato` int DEFAULT '1',
  `direction_id` tinyint(1) DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `corse_fermate`
--

CREATE TABLE `corse_fermate` (
  `id_corse_f` int NOT NULL,
  `id_corsa` int NOT NULL,
  `id_sott` int NOT NULL,
  `orario` time NOT NULL DEFAULT '00:00:00',
  `ordine` int NOT NULL DEFAULT '0',
  `giornoDopo` int DEFAULT '0',
  `giornoDopo1` int DEFAULT '0',
  `giornoDopo2` int DEFAULT '0',
  `giornoDopo3` int DEFAULT '0',
  `distance` float DEFAULT NULL,
  `stato` int DEFAULT '1',
  `gtfs` int DEFAULT '1',
  `gtfs2` int DEFAULT '0',
  `gtfs3` int DEFAULT '0',
  `lat` float DEFAULT NULL,
  `lon` float DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `corse_liste`
--

CREATE TABLE `corse_liste` (
  `id_csl` int NOT NULL,
  `id_corsa` int NOT NULL,
  `corse` varchar(50) NOT NULL,
  `stato` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `dipendenze`
--

CREATE TABLE `dipendenze` (
  `id_dp` int NOT NULL,
  `id_Master` int NOT NULL,
  `id_Child` int NOT NULL,
  `profilo` int NOT NULL DEFAULT '0',
  `stato` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `email_inviate`
--

CREATE TABLE `email_inviate` (
  `id_em` int NOT NULL,
  `id_vg` int NOT NULL,
  `cadenza` int NOT NULL,
  `data` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `fermate_data`
--

CREATE TABLE `fermate_data` (
  `id_fermdata` int NOT NULL,
  `id_sott` int NOT NULL,
  `nome` varchar(150) DEFAULT '-',
  `data_attiv` date DEFAULT '1970-01-01',
  `data_fin` date DEFAULT '1970-01-01',
  `stato` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `gestpay_notifications`
--

CREATE TABLE `gestpay_notifications` (
  `id` int NOT NULL,
  `shopLogin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pag` int DEFAULT '1',
  `CryptedString` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `deCryptatedString` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ErrorCode` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ErrorDescription` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `apikey` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '-',
  `custom_info` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '-',
  `amount` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '-',
  `txn_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '-',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `linee`
--

CREATE TABLE `linee` (
  `id_linea` int NOT NULL,
  `nome` varchar(90) NOT NULL,
  `colore` varchar(20) DEFAULT NULL,
  `stato` int DEFAULT '1',
  `visualizzato` int DEFAULT '1',
  `id_az` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `localita`
--

CREATE TABLE `localita` (
  `id_lc` int NOT NULL,
  `nome` varchar(100) NOT NULL,
  `colore` varchar(10) NOT NULL,
  `stato` int NOT NULL DEFAULT '1',
  `ordine` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `mail_sett`
--

CREATE TABLE `mail_sett` (
  `id_sett` int NOT NULL,
  `email1` varchar(100) NOT NULL,
  `user1` varchar(100) NOT NULL,
  `pass1` varchar(100) NOT NULL,
  `oggetto1` varchar(100) NOT NULL,
  `email2` varchar(100) NOT NULL,
  `user2` varchar(100) NOT NULL,
  `pass2` varchar(100) NOT NULL,
  `oggetto2` varchar(100) NOT NULL,
  `email3` varchar(100) NOT NULL,
  `user3` varchar(100) NOT NULL,
  `pass3` varchar(100) NOT NULL,
  `oggetto3` varchar(100) NOT NULL,
  `smtp` varchar(100) NOT NULL,
  `smtpport` int NOT NULL,
  `smtpsecurity` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `mezzi`
--

CREATE TABLE `mezzi` (
  `id_mz` int NOT NULL,
  `id_mztipo` int DEFAULT '0',
  `nome` varchar(100) NOT NULL,
  `targa` varchar(10) NOT NULL,
  `posti` int DEFAULT '57',
  `anno` date DEFAULT '1970-01-01',
  `foto` varchar(255) DEFAULT '-',
  `immagine_veicolo` varchar(255) DEFAULT '-',
  `nbici` int DEFAULT NULL,
  `ncarr` int DEFAULT NULL,
  `stato` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `mezzi_corse`
--

CREATE TABLE `mezzi_corse` (
  `id_mzc` int NOT NULL,
  `id_mz` int NOT NULL,
  `id_corsa` int NOT NULL,
  `id_az` int NOT NULL,
  `da` date NOT NULL,
  `a` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mezzi_date`
--

CREATE TABLE `mezzi_date` (
  `id_mz_dt` int NOT NULL,
  `data` date NOT NULL,
  `al` date DEFAULT '1970-01-01',
  `id_linea` int DEFAULT '1',
  `id_corsa` varchar(100) DEFAULT '1',
  `n` int NOT NULL,
  `posti` int DEFAULT '57'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `mezzi_mappe`
--

CREATE TABLE `mezzi_mappe` (
  `id_mztipo` int NOT NULL,
  `nome` varchar(150) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `def` int DEFAULT '0',
  `piani` int DEFAULT '1',
  `posti1` int NOT NULL,
  `posti2` int DEFAULT '0',
  `str` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT '',
  `nbici` int DEFAULT '0',
  `ncarr` int DEFAULT '0',
  `str_posti` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `id_az` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nexi_logs`
--

CREATE TABLE `nexi_logs` (
  `id` int UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `phase` varchar(64) NOT NULL,
  `payload` longtext,
  `context` longtext,
  `remote_addr` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pacchetti`
--

CREATE TABLE `pacchetti` (
  `id_pacchetto` int NOT NULL,
  `nome` varchar(150) DEFAULT '-',
  `data_ini` datetime DEFAULT NULL,
  `data_fin` datetime DEFAULT NULL,
  `pag` int DEFAULT '1',
  `pers_max` int DEFAULT '10',
  `disponibilità` int DEFAULT '100',
  `stato` int DEFAULT '1',
  `tempo_offerta` int NOT NULL DEFAULT '0',
  `visibile_da` datetime DEFAULT NULL,
  `visibile_a` datetime DEFAULT NULL,
  `ripete` int DEFAULT '1',
  `giorni_sett` varchar(20) DEFAULT '0,1,2,3,4,5,6',
  `categoria` int DEFAULT '0',
  `prezzo_ini` float DEFAULT '0',
  `prezzo_scontato` float DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `pacchetti_editor`
--

CREATE TABLE `pacchetti_editor` (
  `id_ed` int NOT NULL,
  `id_pacchetto` int NOT NULL,
  `primo` int DEFAULT '0',
  `text` longtext,
  `info` varchar(150) DEFAULT NULL,
  `data` datetime(2) DEFAULT NULL,
  `stato` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `pacchetti_prezzo`
--

CREATE TABLE `pacchetti_prezzo` (
  `id_przpc` int NOT NULL,
  `id_pacchetto` int NOT NULL,
  `daggscadenza` int NOT NULL DEFAULT '0',
  `aggscadenza` int NOT NULL DEFAULT '0',
  `prezzosingolo` float NOT NULL,
  `stato` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `pacchetti_sconti`
--

CREATE TABLE `pacchetti_sconti` (
  `id_cod` int NOT NULL,
  `nome` varchar(255) DEFAULT '-',
  `codice` varchar(10) NOT NULL,
  `sconto` int DEFAULT '0',
  `partenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `scadenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `giorni_sett` varchar(20) DEFAULT '-',
  `autom` int DEFAULT '0',
  `pst` int DEFAULT '0',
  `modific` int NOT NULL DEFAULT '1',
  `finoapersone` int NOT NULL,
  `stato` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `page_visits`
--

CREATE TABLE `page_visits` (
  `id` int NOT NULL,
  `id_ut` int NOT NULL,
  `page_url` varchar(255) NOT NULL,
  `visit_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `user_ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `payment_errors`
--

CREATE TABLE `payment_errors` (
  `id` int NOT NULL,
  `id_vg` int DEFAULT '0',
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `order_id` varchar(255) DEFAULT NULL,
  `error_message` text,
  `payload` text,
  `liability` varchar(255) DEFAULT NULL,
  `cardholder_name` varchar(255) DEFAULT NULL,
  `card_number_last4` varchar(4) DEFAULT NULL,
  `card_type` varchar(50) DEFAULT NULL,
  `user_ip` varchar(45) DEFAULT NULL,
  `user_agent` text
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `provReg`
--

CREATE TABLE `provReg` (
  `id_prov` int NOT NULL,
  `provincia` varchar(23) NOT NULL,
  `regione` varchar(23) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `prz_bag`
--

CREATE TABLE `prz_bag` (
  `id_przbg` int NOT NULL,
  `txt` varchar(100) DEFAULT '-',
  `da` date NOT NULL,
  `a` date NOT NULL,
  `peso` varchar(200) NOT NULL,
  `dim` varchar(50) NOT NULL,
  `prz` float DEFAULT '0',
  `max_qnt` int DEFAULT '5',
  `incremento` int DEFAULT '0',
  `info` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '',
  `tipobg` int DEFAULT '0',
  `stato` int DEFAULT '1',
  `id_az` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `prz_date`
--

CREATE TABLE `prz_date` (
  `id_przdt` int NOT NULL,
  `da` date NOT NULL,
  `a` date NOT NULL,
  `corsa` varchar(200) NOT NULL,
  `ad` int DEFAULT '1',
  `bam` int DEFAULT '1',
  `stato` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `pst_date`
--

CREATE TABLE `pst_date` (
  `id_pst` int NOT NULL,
  `da` date NOT NULL,
  `a` date NOT NULL,
  `corsa` varchar(100) NOT NULL,
  `posti` varchar(150) NOT NULL,
  `stato` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `pst_prz`
--

CREATE TABLE `pst_prz` (
  `id_pstprz` int NOT NULL,
  `tipo` int DEFAULT '0',
  `prz` float NOT NULL DEFAULT '0',
  `id_az` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `regole`
--

CREATE TABLE `regole` (
  `id_r` int NOT NULL,
  `id_sott1` int NOT NULL,
  `id_sott2` int NOT NULL DEFAULT '0',
  `id_linea` int NOT NULL DEFAULT '0',
  `date` varchar(255) DEFAULT NULL,
  `date_permesse` varchar(255) DEFAULT NULL,
  `giorni_sett` varchar(50) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `regole_autisti`
--

CREATE TABLE `regole_autisti` (
  `id_ra` int NOT NULL,
  `id_ut` int NOT NULL,
  `da` date NOT NULL,
  `a` date NOT NULL,
  `corse` longtext NOT NULL,
  `stato` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `regole_corse`
--

CREATE TABLE `regole_corse` (
  `id_rc` int NOT NULL,
  `id_sott` int NOT NULL,
  `corse` varchar(50) DEFAULT '0',
  `giorni_sett` varchar(50) NOT NULL DEFAULT '0',
  `da` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `a` datetime NOT NULL DEFAULT '1970-01-01 00:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `regole_linee`
--

CREATE TABLE `regole_linee` (
  `id_rl` int NOT NULL,
  `id_linea` int NOT NULL DEFAULT '0',
  `corse` varchar(100) DEFAULT '0',
  `da` datetime NOT NULL,
  `a` datetime NOT NULL,
  `giorni_sett` varchar(50) DEFAULT '-'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `regole_tratta`
--

CREATE TABLE `regole_tratta` (
  `id_rtr` int NOT NULL,
  `id_sott1` int NOT NULL,
  `id_sott2` int NOT NULL,
  `impedite_permesse` int NOT NULL,
  `corse` varchar(255) NOT NULL,
  `da` date NOT NULL,
  `a` date NOT NULL,
  `stato` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `report_giorno`
--

CREATE TABLE `report_giorno` (
  `id_r` int NOT NULL,
  `id_ut` int NOT NULL,
  `id_mz` int DEFAULT '0',
  `id_linea` int DEFAULT '0',
  `id_corsa` int DEFAULT '0',
  `data` date DEFAULT '1970-01-01'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `sconti`
--

CREATE TABLE `sconti` (
  `id_cod` int NOT NULL,
  `nome` varchar(255) DEFAULT '-',
  `codice` varchar(15) NOT NULL,
  `sconto` int DEFAULT '0',
  `partenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `scadenza` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  `giorni_sett` varchar(20) DEFAULT '-',
  `autom` int DEFAULT '0',
  `ar` int DEFAULT '0',
  `pst` int DEFAULT '57',
  `id_linea` varchar(50) DEFAULT '0',
  `id_corsa` varchar(250) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT '0',
  `modific` int NOT NULL DEFAULT '1',
  `avviso` int DEFAULT '0',
  `avviso_str` varchar(255) DEFAULT NULL,
  `stato` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `tratte_sottoc`
--

CREATE TABLE `tratte_sottoc` (
  `id_sott` int NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descsott` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `stato` int DEFAULT '1',
  `localita` int NOT NULL DEFAULT '0',
  `sos_da` datetime DEFAULT '1970-01-01 00:00:00',
  `sos_a` datetime DEFAULT '1970-01-01 00:00:00',
  `indirizzo` varchar(255) DEFAULT NULL,
  `comune` varchar(100) DEFAULT NULL,
  `provincia` varchar(50) DEFAULT NULL,
  `paese` varchar(50) DEFAULT NULL,
  `country_code` char(2) DEFAULT 'IT',
  `timezone` varchar(50) DEFAULT 'Europe/Rome'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `tratte_sottoc_tratte`
--

CREATE TABLE `tratte_sottoc_tratte` (
  `id_tst` int NOT NULL,
  `id_sott1` int NOT NULL,
  `id_sott2` int NOT NULL,
  `prezzo` float NOT NULL,
  `stato` int NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `utenti`
--

CREATE TABLE `utenti` (
  `id_ut` int NOT NULL,
  `nome` varchar(50) NOT NULL,
  `cognome` varchar(50) NOT NULL,
  `citta` varchar(255) DEFAULT '-',
  `cf` varchar(50) DEFAULT '-',
  `email` varchar(255) NOT NULL,
  `pass` varchar(50) NOT NULL,
  `foto` varchar(200) DEFAULT 'default.jpg',
  `tel` varchar(20) NOT NULL,
  `data` date DEFAULT '1970-01-01',
  `stato` int DEFAULT '1',
  `id_vg` int DEFAULT '1',
  `attivo` int DEFAULT '1',
  `profilo` int DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `viaggiatori`
--

CREATE TABLE `viaggiatori` (
  `id_vg` int NOT NULL,
  `nome` varchar(50) NOT NULL,
  `cognome` varchar(50) DEFAULT '-',
  `citta` varchar(255) DEFAULT '-',
  `id_prov` int DEFAULT '0',
  `picf` varchar(20) DEFAULT '-',
  `email` varchar(100) DEFAULT '-',
  `pass` varchar(120) DEFAULT '-',
  `foto` varchar(200) DEFAULT 'default.jpg',
  `tel` varchar(30) NOT NULL,
  `data` date DEFAULT '1970-01-01',
  `profilo` int DEFAULT '0',
  `sconto` int DEFAULT '0',
  `tipo_pag` int NOT NULL DEFAULT '0',
  `conteggio_pag` int DEFAULT '1',
  `comunicazioni` int DEFAULT '1',
  `stato` int DEFAULT '1',
  `google_userid` varchar(255) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `viaggiatori_mail`
--

CREATE TABLE `viaggiatori_mail` (
  `id_em` int NOT NULL,
  `nome` varchar(50) NOT NULL DEFAULT '-',
  `cognome` varchar(50) NOT NULL DEFAULT '-',
  `email` varchar(100) NOT NULL,
  `note` varchar(150) DEFAULT '-',
  `id_em_tipo` int NOT NULL DEFAULT '1',
  `data` date NOT NULL DEFAULT '1970-01-01',
  `id_prov` int DEFAULT '0',
  `stato` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `viaggiatori_mail_time`
--

CREATE TABLE `viaggiatori_mail_time` (
  `id_emt` int NOT NULL,
  `id_em` int NOT NULL,
  `data` datetime NOT NULL,
  `tipo` int NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `viaggiatori_mail_tipo`
--

CREATE TABLE `viaggiatori_mail_tipo` (
  `id_em_tipo` int NOT NULL,
  `nome` varchar(50) NOT NULL,
  `stato` int DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `viaggiatori_temp`
--

CREATE TABLE `viaggiatori_temp` (
  `id_vgt` int NOT NULL,
  `nome` varchar(50) NOT NULL,
  `cognome` varchar(50) NOT NULL,
  `tel` varchar(20) NOT NULL,
  `email` varchar(150) DEFAULT '-',
  `data_reg` date DEFAULT '1970-01-01',
  `stato` int DEFAULT '1'
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `abbcarn_editor`
--
ALTER TABLE `abbcarn_editor`
  ADD PRIMARY KEY (`id_labbcrn`);

--
-- Indexes for table `abbcarn_lista`
--
ALTER TABLE `abbcarn_lista`
  ADD PRIMARY KEY (`id_codabbcarn_l`),
  ADD KEY `id_codabbcarn` (`id_codabbcarn_l`);

--
-- Indexes for table `abbcarn_utenti`
--
ALTER TABLE `abbcarn_utenti`
  ADD PRIMARY KEY (`id_codabbcarn_u`),
  ADD KEY `id_codabbcarn` (`id_codabbcarn_u`);

--
-- Indexes for table `abbcarn_utenti_reg`
--
ALTER TABLE `abbcarn_utenti_reg`
  ADD PRIMARY KEY (`id_abbcarn_reg`),
  ADD KEY `id_abbcarn_reg` (`id_abbcarn_reg`);

--
-- Indexes for table `assicuratori`
--
ALTER TABLE `assicuratori`
  ADD PRIMARY KEY (`id_ass`),
  ADD UNIQUE KEY `id_ass` (`id_ass`);

--
-- Indexes for table `aziende`
--
ALTER TABLE `aziende`
  ADD PRIMARY KEY (`id_az`),
  ADD UNIQUE KEY `id_az` (`id_az`);

--
-- Indexes for table `aziende_comm`
--
ALTER TABLE `aziende_comm`
  ADD PRIMARY KEY (`id_azcomm`);

--
-- Indexes for table `aziende_pag`
--
ALTER TABLE `aziende_pag`
  ADD PRIMARY KEY (`az_p`),
  ADD UNIQUE KEY `az_p` (`az_p`);

--
-- Indexes for table `aziende_pag2`
--
ALTER TABLE `aziende_pag2`
  ADD PRIMARY KEY (`az_p`),
  ADD UNIQUE KEY `az_p` (`az_p`);

--
-- Indexes for table `bagagli`
--
ALTER TABLE `bagagli`
  ADD PRIMARY KEY (`id_pac`);

--
-- Indexes for table `biglietti`
--
ALTER TABLE `biglietti`
  ADD PRIMARY KEY (`id_bg`),
  ADD KEY `id_vgt` (`id_vgt`);

--
-- Indexes for table `biglietti_ipn`
--
ALTER TABLE `biglietti_ipn`
  ADD PRIMARY KEY (`id_ipn`);

--
-- Indexes for table `biglietti_ipn_logs`
--
ALTER TABLE `biglietti_ipn_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `biglietti_log`
--
ALTER TABLE `biglietti_log`
  ADD PRIMARY KEY (`id_b_log`),
  ADD UNIQUE KEY `id_b_log` (`id_b_log`);

--
-- Indexes for table `biglietti_log_orari`
--
ALTER TABLE `biglietti_log_orari`
  ADD PRIMARY KEY (`id_bglo`),
  ADD UNIQUE KEY `id_bglo` (`id_bglo`);

--
-- Indexes for table `biglietti_reg`
--
ALTER TABLE `biglietti_reg`
  ADD UNIQUE KEY `id_bg0` (`id`),
  ADD KEY `id_bg` (`id_bg`),
  ADD KEY `id_bg_2` (`id_bg`);

--
-- Indexes for table `biglietti_rit`
--
ALTER TABLE `biglietti_rit`
  ADD PRIMARY KEY (`id_r`);

--
-- Indexes for table `cadenza`
--
ALTER TABLE `cadenza`
  ADD PRIMARY KEY (`id_cad`);

--
-- Indexes for table `corse`
--
ALTER TABLE `corse`
  ADD PRIMARY KEY (`id_corsa`),
  ADD UNIQUE KEY `id_corsa` (`id_corsa`);

--
-- Indexes for table `corse_fermate`
--
ALTER TABLE `corse_fermate`
  ADD PRIMARY KEY (`id_corse_f`);

--
-- Indexes for table `corse_liste`
--
ALTER TABLE `corse_liste`
  ADD PRIMARY KEY (`id_csl`);

--
-- Indexes for table `dipendenze`
--
ALTER TABLE `dipendenze`
  ADD PRIMARY KEY (`id_dp`);

--
-- Indexes for table `email_inviate`
--
ALTER TABLE `email_inviate`
  ADD PRIMARY KEY (`id_em`);

--
-- Indexes for table `fermate_data`
--
ALTER TABLE `fermate_data`
  ADD PRIMARY KEY (`id_fermdata`);

--
-- Indexes for table `gestpay_notifications`
--
ALTER TABLE `gestpay_notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `linee`
--
ALTER TABLE `linee`
  ADD PRIMARY KEY (`id_linea`),
  ADD KEY `id_linea` (`id_linea`);

--
-- Indexes for table `localita`
--
ALTER TABLE `localita`
  ADD PRIMARY KEY (`id_lc`);

--
-- Indexes for table `mail_sett`
--
ALTER TABLE `mail_sett`
  ADD PRIMARY KEY (`id_sett`);

--
-- Indexes for table `mezzi`
--
ALTER TABLE `mezzi`
  ADD PRIMARY KEY (`id_mz`);

--
-- Indexes for table `mezzi_corse`
--
ALTER TABLE `mezzi_corse`
  ADD PRIMARY KEY (`id_mzc`);

--
-- Indexes for table `mezzi_date`
--
ALTER TABLE `mezzi_date`
  ADD PRIMARY KEY (`id_mz_dt`),
  ADD KEY `id_mz_dt` (`id_mz_dt`);

--
-- Indexes for table `mezzi_mappe`
--
ALTER TABLE `mezzi_mappe`
  ADD PRIMARY KEY (`id_mztipo`);

--
-- Indexes for table `nexi_logs`
--
ALTER TABLE `nexi_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `phase_idx` (`phase`),
  ADD KEY `created_at_idx` (`created_at`);

--
-- Indexes for table `pacchetti`
--
ALTER TABLE `pacchetti`
  ADD PRIMARY KEY (`id_pacchetto`);

--
-- Indexes for table `pacchetti_editor`
--
ALTER TABLE `pacchetti_editor`
  ADD PRIMARY KEY (`id_ed`);

--
-- Indexes for table `pacchetti_prezzo`
--
ALTER TABLE `pacchetti_prezzo`
  ADD PRIMARY KEY (`id_przpc`);

--
-- Indexes for table `pacchetti_sconti`
--
ALTER TABLE `pacchetti_sconti`
  ADD PRIMARY KEY (`id_cod`);

--
-- Indexes for table `page_visits`
--
ALTER TABLE `page_visits`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payment_errors`
--
ALTER TABLE `payment_errors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `provReg`
--
ALTER TABLE `provReg`
  ADD PRIMARY KEY (`id_prov`);

--
-- Indexes for table `prz_bag`
--
ALTER TABLE `prz_bag`
  ADD PRIMARY KEY (`id_przbg`);

--
-- Indexes for table `prz_date`
--
ALTER TABLE `prz_date`
  ADD PRIMARY KEY (`id_przdt`);

--
-- Indexes for table `pst_date`
--
ALTER TABLE `pst_date`
  ADD PRIMARY KEY (`id_pst`);

--
-- Indexes for table `pst_prz`
--
ALTER TABLE `pst_prz`
  ADD PRIMARY KEY (`id_pstprz`);

--
-- Indexes for table `regole`
--
ALTER TABLE `regole`
  ADD PRIMARY KEY (`id_r`),
  ADD UNIQUE KEY `id_r` (`id_r`);

--
-- Indexes for table `regole_autisti`
--
ALTER TABLE `regole_autisti`
  ADD PRIMARY KEY (`id_ra`);

--
-- Indexes for table `regole_corse`
--
ALTER TABLE `regole_corse`
  ADD PRIMARY KEY (`id_rc`);

--
-- Indexes for table `regole_linee`
--
ALTER TABLE `regole_linee`
  ADD PRIMARY KEY (`id_rl`),
  ADD UNIQUE KEY `id_rl` (`id_rl`);

--
-- Indexes for table `regole_tratta`
--
ALTER TABLE `regole_tratta`
  ADD PRIMARY KEY (`id_rtr`);

--
-- Indexes for table `report_giorno`
--
ALTER TABLE `report_giorno`
  ADD PRIMARY KEY (`id_r`),
  ADD UNIQUE KEY `id_r` (`id_r`);

--
-- Indexes for table `sconti`
--
ALTER TABLE `sconti`
  ADD PRIMARY KEY (`id_cod`),
  ADD KEY `id_cod` (`id_cod`);

--
-- Indexes for table `tratte_sottoc`
--
ALTER TABLE `tratte_sottoc`
  ADD PRIMARY KEY (`id_sott`);

--
-- Indexes for table `tratte_sottoc_tratte`
--
ALTER TABLE `tratte_sottoc_tratte`
  ADD PRIMARY KEY (`id_tst`);

--
-- Indexes for table `utenti`
--
ALTER TABLE `utenti`
  ADD PRIMARY KEY (`id_ut`);

--
-- Indexes for table `viaggiatori`
--
ALTER TABLE `viaggiatori`
  ADD PRIMARY KEY (`id_vg`);

--
-- Indexes for table `viaggiatori_mail`
--
ALTER TABLE `viaggiatori_mail`
  ADD PRIMARY KEY (`id_em`);

--
-- Indexes for table `viaggiatori_mail_time`
--
ALTER TABLE `viaggiatori_mail_time`
  ADD PRIMARY KEY (`id_emt`);

--
-- Indexes for table `viaggiatori_mail_tipo`
--
ALTER TABLE `viaggiatori_mail_tipo`
  ADD PRIMARY KEY (`id_em_tipo`);

--
-- Indexes for table `viaggiatori_temp`
--
ALTER TABLE `viaggiatori_temp`
  ADD PRIMARY KEY (`id_vgt`),
  ADD UNIQUE KEY `id_vgt` (`id_vgt`),
  ADD KEY `id_vgt_2` (`id_vgt`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `abbcarn_editor`
--
ALTER TABLE `abbcarn_editor`
  MODIFY `id_labbcrn` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `abbcarn_lista`
--
ALTER TABLE `abbcarn_lista`
  MODIFY `id_codabbcarn_l` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `abbcarn_utenti`
--
ALTER TABLE `abbcarn_utenti`
  MODIFY `id_codabbcarn_u` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `abbcarn_utenti_reg`
--
ALTER TABLE `abbcarn_utenti_reg`
  MODIFY `id_abbcarn_reg` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assicuratori`
--
ALTER TABLE `assicuratori`
  MODIFY `id_ass` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `aziende`
--
ALTER TABLE `aziende`
  MODIFY `id_az` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `aziende_comm`
--
ALTER TABLE `aziende_comm`
  MODIFY `id_azcomm` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `aziende_pag`
--
ALTER TABLE `aziende_pag`
  MODIFY `az_p` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `aziende_pag2`
--
ALTER TABLE `aziende_pag2`
  MODIFY `az_p` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bagagli`
--
ALTER TABLE `bagagli`
  MODIFY `id_pac` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biglietti`
--
ALTER TABLE `biglietti`
  MODIFY `id_bg` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biglietti_ipn`
--
ALTER TABLE `biglietti_ipn`
  MODIFY `id_ipn` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biglietti_ipn_logs`
--
ALTER TABLE `biglietti_ipn_logs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biglietti_log`
--
ALTER TABLE `biglietti_log`
  MODIFY `id_b_log` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biglietti_log_orari`
--
ALTER TABLE `biglietti_log_orari`
  MODIFY `id_bglo` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biglietti_reg`
--
ALTER TABLE `biglietti_reg`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `biglietti_rit`
--
ALTER TABLE `biglietti_rit`
  MODIFY `id_r` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cadenza`
--
ALTER TABLE `cadenza`
  MODIFY `id_cad` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `corse`
--
ALTER TABLE `corse`
  MODIFY `id_corsa` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `corse_fermate`
--
ALTER TABLE `corse_fermate`
  MODIFY `id_corse_f` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `corse_liste`
--
ALTER TABLE `corse_liste`
  MODIFY `id_csl` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dipendenze`
--
ALTER TABLE `dipendenze`
  MODIFY `id_dp` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_inviate`
--
ALTER TABLE `email_inviate`
  MODIFY `id_em` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fermate_data`
--
ALTER TABLE `fermate_data`
  MODIFY `id_fermdata` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gestpay_notifications`
--
ALTER TABLE `gestpay_notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `linee`
--
ALTER TABLE `linee`
  MODIFY `id_linea` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `localita`
--
ALTER TABLE `localita`
  MODIFY `id_lc` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mail_sett`
--
ALTER TABLE `mail_sett`
  MODIFY `id_sett` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mezzi`
--
ALTER TABLE `mezzi`
  MODIFY `id_mz` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mezzi_corse`
--
ALTER TABLE `mezzi_corse`
  MODIFY `id_mzc` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mezzi_date`
--
ALTER TABLE `mezzi_date`
  MODIFY `id_mz_dt` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mezzi_mappe`
--
ALTER TABLE `mezzi_mappe`
  MODIFY `id_mztipo` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nexi_logs`
--
ALTER TABLE `nexi_logs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pacchetti`
--
ALTER TABLE `pacchetti`
  MODIFY `id_pacchetto` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pacchetti_editor`
--
ALTER TABLE `pacchetti_editor`
  MODIFY `id_ed` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pacchetti_sconti`
--
ALTER TABLE `pacchetti_sconti`
  MODIFY `id_cod` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `page_visits`
--
ALTER TABLE `page_visits`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_errors`
--
ALTER TABLE `payment_errors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `provReg`
--
ALTER TABLE `provReg`
  MODIFY `id_prov` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prz_bag`
--
ALTER TABLE `prz_bag`
  MODIFY `id_przbg` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prz_date`
--
ALTER TABLE `prz_date`
  MODIFY `id_przdt` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pst_date`
--
ALTER TABLE `pst_date`
  MODIFY `id_pst` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pst_prz`
--
ALTER TABLE `pst_prz`
  MODIFY `id_pstprz` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `regole`
--
ALTER TABLE `regole`
  MODIFY `id_r` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `regole_autisti`
--
ALTER TABLE `regole_autisti`
  MODIFY `id_ra` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `regole_corse`
--
ALTER TABLE `regole_corse`
  MODIFY `id_rc` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `regole_linee`
--
ALTER TABLE `regole_linee`
  MODIFY `id_rl` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `regole_tratta`
--
ALTER TABLE `regole_tratta`
  MODIFY `id_rtr` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `report_giorno`
--
ALTER TABLE `report_giorno`
  MODIFY `id_r` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sconti`
--
ALTER TABLE `sconti`
  MODIFY `id_cod` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tratte_sottoc`
--
ALTER TABLE `tratte_sottoc`
  MODIFY `id_sott` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tratte_sottoc_tratte`
--
ALTER TABLE `tratte_sottoc_tratte`
  MODIFY `id_tst` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `utenti`
--
ALTER TABLE `utenti`
  MODIFY `id_ut` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `viaggiatori`
--
ALTER TABLE `viaggiatori`
  MODIFY `id_vg` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `viaggiatori_mail`
--
ALTER TABLE `viaggiatori_mail`
  MODIFY `id_em` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `viaggiatori_mail_time`
--
ALTER TABLE `viaggiatori_mail_time`
  MODIFY `id_emt` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `viaggiatori_mail_tipo`
--
ALTER TABLE `viaggiatori_mail_tipo`
  MODIFY `id_em_tipo` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `viaggiatori_temp`
--
ALTER TABLE `viaggiatori_temp`
  MODIFY `id_vgt` int NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
