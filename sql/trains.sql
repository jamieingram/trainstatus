# ************************************************************
# Sequel Pro SQL dump
# Version 4096
#
# http://www.sequelpro.com/
# http://code.google.com/p/sequel-pro/
#
# Host: 127.0.0.1 (MySQL 5.5.38-0ubuntu0.12.04.1)
# Database: trains
# Generation Time: 2014-09-04 08:05:00 +0000
# ************************************************************


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


# Dump of table service
# ------------------------------------------------------------

DROP TABLE IF EXISTS `service`;

CREATE TABLE `service` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `serviceID` varchar(30) NOT NULL DEFAULT '',
  `location` varchar(30) NOT NULL DEFAULT '',
  `from_station` varchar(30) NOT NULL DEFAULT '',
  `from_code` char(3) NOT NULL DEFAULT '',
  `to_station` varchar(30) NOT NULL DEFAULT '',
  `to_code` char(3) NOT NULL DEFAULT '',
  `sta` varchar(10) NOT NULL DEFAULT '',
  `eta` varchar(10) NOT NULL DEFAULT '',
  `ata` varchar(10) NOT NULL DEFAULT '',
  `std` varchar(10) NOT NULL DEFAULT '',
  `etd` varchar(10) NOT NULL DEFAULT '',
  `atd` varchar(10) NOT NULL DEFAULT '',
  `platform` int(11) DEFAULT NULL,
  `isCancelled` tinyint(1) NOT NULL DEFAULT '0',
  `overdueMessage` text,
  `distruptionReason` text,
  `isDelayed` tinyint(4) NOT NULL DEFAULT '0',
  `delayLength` int(11) DEFAULT NULL,
  `notificationSent` tinyint(4) NOT NULL DEFAULT '0',
  `lastUpdated` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;




/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
