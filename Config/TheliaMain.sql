
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- kelkooxml_feed
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `kelkooxml_feed`;

CREATE TABLE `kelkooxml_feed`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `label` VARCHAR(255),
    `lang_id` INTEGER NOT NULL,
    `currency_id` INTEGER NOT NULL,
    `country_id` INTEGER NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `fi_kelkooxml_feed_lang_id` (`lang_id`),
    INDEX `fi_kelkooxml_feed_currency_id` (`currency_id`),
    INDEX `fi_kelkooxml_feed_country_id` (`country_id`),
    CONSTRAINT `fk_kelkooxml_feed_lang_id`
        FOREIGN KEY (`lang_id`)
        REFERENCES `lang` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,
    CONSTRAINT `fk_kelkooxml_feed_currency_id`
        FOREIGN KEY (`currency_id`)
        REFERENCES `currency` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,
    CONSTRAINT `fk_kelkooxml_feed_country_id`
        FOREIGN KEY (`country_id`)
        REFERENCES `country` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- kelkooxml_xml_field_association
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `kelkooxml_xml_field_association`;

CREATE TABLE `kelkooxml_xml_field_association`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `xml_field` VARCHAR(255) NOT NULL,
    `association_type` INTEGER NOT NULL,
    `fixed_value` VARCHAR(255),
    `id_related_attribute` INTEGER,
    `id_related_feature` INTEGER,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unique_kelkooxml_xml_field_association_xml_field` (`xml_field`),
    INDEX `fi_kelkooxml_xml_field_association_id_attribute` (`id_related_attribute`),
    INDEX `fi_kelkooxml_xml_field_association_id_feature` (`id_related_feature`),
    CONSTRAINT `fk_kelkooxml_xml_field_association_id_attribute`
        FOREIGN KEY (`id_related_attribute`)
        REFERENCES `attribute` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,
    CONSTRAINT `fk_kelkooxml_xml_field_association_id_feature`
        FOREIGN KEY (`id_related_feature`)
        REFERENCES `feature` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- kelkooxml_log
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `kelkooxml_log`;

CREATE TABLE `kelkooxml_log`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `feed_id` INTEGER NOT NULL,
    `separation` TINYINT(1) NOT NULL,
    `level` INTEGER NOT NULL,
    `pse_id` INTEGER,
    `message` TEXT NOT NULL,
    `help` TEXT,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    INDEX `fi_kelkooxml_log_feed_id` (`feed_id`),
    INDEX `fi_kelkooxml_log_pse_id` (`pse_id`),
    CONSTRAINT `fk_kelkooxml_log_feed_id`
        FOREIGN KEY (`feed_id`)
        REFERENCES `kelkooxml_feed` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,
    CONSTRAINT `fk_kelkooxml_log_pse_id`
        FOREIGN KEY (`pse_id`)
        REFERENCES `product_sale_elements` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
