CREATE TABLE `xapi_actors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned DEFAULT NULL,
  `type` enum('agent','group') COLLATE latin1_german1_ci NOT NULL,
  `name` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `mbox` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `mbox_sha1_sum` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `open_id` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `has_account` tinyint(3) unsigned NOT NULL,
  `account_name` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `account_home_page` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `xapi_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `usage_type` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `content_type` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `length` int(10) unsigned DEFAULT NULL,
  `sha2` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `display` text COLLATE latin1_german1_ci NOT NULL,
  `description` text COLLATE latin1_german1_ci DEFAULT NULL,
  `file_url` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `content` blob DEFAULT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `xapi_course_lrs` (
  `course_id` varchar(255) COLLATE latin1_german1_ci NOT NULL,
  `lrs_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`course_id`, `lrs_id`),
  UNIQUE INDEX (`course_id`)
);

CREATE TABLE `xapi_lrs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `created_by` varchar(255) COLLATE latin1_german1_ci NOT NULL,
  PRIMARY KEY (`id`)
);

CREATE TABLE `xapi_statement_attachment` (
  `statement_id` varchar(36) COLLATE latin1_german1_ci NOT NULL,
  `attachment_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`statement_id`, `attachment_id`)
);

CREATE TABLE `xapi_statements` (
  `uuid` varchar(36) COLLATE latin1_german1_ci NOT NULL,
  `lrs_id` int(10) unsigned NOT NULL,
  `actor_id` int(10) unsigned NOT NULL,
  `verb_iri` varchar(255) COLLATE latin1_german1_ci NOT NULL,
  `verb_display` text COLLATE latin1_german1_ci NOT NULL,
  `object_type` enum('activity','statement_reference', 'sub_statement') COLLATE latin1_german1_ci NOT NULL,
  `activity_id` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `activity_name` text COLLATE latin1_german1_ci DEFAULT NULL,
  `activity_description` text COLLATE latin1_german1_ci DEFAULT NULL,
  `activity_type` varchar(255) COLLATE latin1_german1_ci DEFAULT NULL,
  `referenced_statement_id` varchar(36) COLLATE latin1_german1_ci DEFAULT NULL,
  `has_result` tinyint(3) unsigned NOT NULL,
  `scaled` double DEFAULT NULL,
  `raw` double DEFAULT NULL,
  `min` double DEFAULT NULL,
  `max` double DEFAULT NULL,
  `success` tinyint(3) unsigned DEFAULT NULL,
  `completion` tinyint(3) unsigned DEFAULT NULL,
  `response` text DEFAULT NULL,
  `duration` text DEFAULT NULL,
  `extensions` text DEFAULT NULL,
  `authority_id` int(10) DEFAULT NULL,
  `created` int(10) DEFAULT NULL,
  `stored` int(10) DEFAULT NULL,
  PRIMARY KEY (`uuid`)
);
