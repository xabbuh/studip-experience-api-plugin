CREATE TABLE `xapi_actors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned DEFAULT NULL,
  `type` enum('agent','group') COLLATE latin1_german1_ci NOT NULL,
  `name` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `mbox` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `mbox_sha1_sum` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `open_id` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `xapi_objects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` varchar(255) COLLATE latin1_german1_ci NOT NULL,
  `name` text COLLATE latin1_german1_ci DEFAULT NULL,
  `description` text COLLATE latin1_german1_ci DEFAULT NULL,
  `type` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `statement_id` varchar(36) COLLATE latin1_german1_ci NOT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `xapi_statements` (
  `uuid` varchar(36) COLLATE latin1_german1_ci NOT NULL,
  `verb_id` int(10) unsigned NOT NULL,
  `actor_id` int(10) unsigned NOT NULL,
  `object_id` int(10) unsigned NOT NULL,
  `object_type` enum('activity','statement_reference') COLLATE latin1_german1_ci NOT NULL,
  `has_result` tinyint(3) unsigned NOT NULL,
  `scaled` double DEFAULT NULL,
  `raw` double DEFAULT NULL,
  `min` double DEFAULT NULL,
  `max` double DEFAULT NULL,
  `success` tinyint(3) unsigned DEFAULT NULL,
  `completion` tinyint(3) unsigned DEFAULT NULL,
  `response` text DEFAULT NULL,
  `duration` text DEFAULT NULL,
  PRIMARY KEY (`uuid`)
);

CREATE TABLE `xapi_verbs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `iri` varchar(255) COLLATE latin1_german1_ci NOT NULL,
  `display` text COLLATE latin1_german1_ci NOT NULL,
  PRIMARY KEY (`id`)
);
