-- Dibuat otomatis oleh alat/sqlite_ke_mysql.py dari database SQLite SIEMI.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `units`;
CREATE TABLE `units` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(255) NOT NULL,
  `name` MEDIUMTEXT NOT NULL,
  `type` MEDIUMTEXT NOT NULL,
  `parent_id` INT,
  `is_active` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `jenjang` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_units_code` (`code`),
  FOREIGN KEY (`parent_id`) REFERENCES `units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nip` VARCHAR(255),
  `name` MEDIUMTEXT NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` MEDIUMTEXT NOT NULL,
  `role` MEDIUMTEXT NOT NULL,
  `jabatan` MEDIUMTEXT,
  `unit_id` INT,
  `is_active` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_nip` (`nip`),
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `periods`;
CREATE TABLE `periods` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` MEDIUMTEXT NOT NULL,
  `year` INT NOT NULL,
  `start_date` MEDIUMTEXT,
  `end_date` MEDIUMTEXT,
  `status` VARCHAR(255) NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `standards`;
CREATE TABLE `standards` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `period_id` INT NOT NULL,
  `code` VARCHAR(255) NOT NULL,
  `category` MEDIUMTEXT NOT NULL,
  `title` MEDIUMTEXT NOT NULL,
  `statement` MEDIUMTEXT,
  `rationale` MEDIUMTEXT,
  `reference_iso` MEDIUMTEXT,
  `reference_lam` MEDIUMTEXT,
  `scope` MEDIUMTEXT,
  `status` VARCHAR(255) NOT NULL DEFAULT 'draft',
  `ditetapkan_at` MEDIUMTEXT,
  `ditetapkan_by` INT,
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ranah` MEDIUMTEXT,
  `kelompok` MEDIUMTEXT,
  `sifat` VARCHAR(255) NOT NULL DEFAULT 'wajib',
  `strategy` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_standards_period_id_code` (`period_id`,`code`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`ditetapkan_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`period_id`) REFERENCES `periods`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `standard_indicators`;
CREATE TABLE `standard_indicators` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `standard_id` INT NOT NULL,
  `code` MEDIUMTEXT NOT NULL,
  `indicator` MEDIUMTEXT NOT NULL,
  `target` MEDIUMTEXT,
  `satuan` MEDIUMTEXT,
  `strategy` MEDIUMTEXT,
  `deadline` MEDIUMTEXT,
  `sort_order` INT NOT NULL DEFAULT 0,
  `statement_id` INT,
  `source_ref_item_id` INT,
  `target_skor` MEDIUMTEXT,
  `bukti_sahih` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`standard_id`) REFERENCES `standards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `standard_assignments`;
CREATE TABLE `standard_assignments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `standard_id` INT NOT NULL,
  `unit_id` INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_standard_assignments_standard_id_unit_id` (`standard_id`,`unit_id`),
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`standard_id`) REFERENCES `standards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `implementations`;
CREATE TABLE `implementations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `period_id` INT NOT NULL,
  `standard_id` INT NOT NULL,
  `indicator_id` INT NOT NULL,
  `unit_id` INT NOT NULL,
  `realisasi` MEDIUMTEXT,
  `capaian_nilai` DOUBLE,
  `bukti_uraian` MEDIUMTEXT,
  `bukti_url` MEDIUMTEXT,
  `kendala` MEDIUMTEXT,
  `status` VARCHAR(255) NOT NULL DEFAULT 'draft',
  `submitted_by` INT,
  `submitted_at` MEDIUMTEXT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `skor` DOUBLE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_implementations_period_id_indicator_id_unit_id` (`period_id`,`indicator_id`,`unit_id`),
  FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`indicator_id`) REFERENCES `standard_indicators`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`standard_id`) REFERENCES `standards`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`period_id`) REFERENCES `periods`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audits`;
CREATE TABLE `audits` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `period_id` INT NOT NULL,
  `unit_id` INT NOT NULL,
  `ba_number` MEDIUMTEXT,
  `lead_auditor_id` INT,
  `auditee_id` INT,
  `scheduled_date` MEDIUMTEXT,
  `executed_date` MEDIUMTEXT,
  `tempat` MEDIUMTEXT,
  `scope` MEDIUMTEXT,
  `kesimpulan` MEDIUMTEXT,
  `status` VARCHAR(255) NOT NULL DEFAULT 'dijadwalkan',
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `scheduled_time` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`auditee_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`lead_auditor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`period_id`) REFERENCES `periods`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audit_members`;
CREATE TABLE `audit_members` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `audit_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `peran` VARCHAR(255) NOT NULL DEFAULT 'Anggota Auditor',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_members_audit_id_user_id` (`audit_id`,`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`audit_id`) REFERENCES `audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `program_kerja`;
CREATE TABLE `program_kerja` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `unit_id` INT NOT NULL,
  `period_id` INT NOT NULL,
  `jenis` VARCHAR(255) NOT NULL DEFAULT 'utama',
  `nama` MEDIUMTEXT NOT NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'draft',
  `ditetapkan_at` MEDIUMTEXT,
  `ditetapkan_by` INT,
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sub_unit_id` INT,
  `tahun` INT,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`sub_unit_id`) REFERENCES `units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`ditetapkan_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`period_id`) REFERENCES `periods`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sasaran_mutu`;
CREATE TABLE `sasaran_mutu` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `program_kerja_id` INT NOT NULL,
  `sasaran` MEDIUMTEXT NOT NULL,
  `target` MEDIUMTEXT NOT NULL,
  `satuan` MEDIUMTEXT,
  `periode_evaluasi` VARCHAR(255) NOT NULL DEFAULT 'bulanan',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tipe_nilai` VARCHAR(255) NOT NULL DEFAULT 'angka',
  `skala_peringkat` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`program_kerja_id`) REFERENCES `program_kerja`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audit_assessments`;
CREATE TABLE `audit_assessments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `audit_id` INT NOT NULL,
  `indicator_id` INT,
  `sasaran_mutu_id` INT,
  `implementation_id` INT,
  `skor` DOUBLE,
  `capaian_persen` DOUBLE,
  `verdict` VARCHAR(255) NOT NULL DEFAULT 'sesuai',
  `catatan_auditor` MEDIUMTEXT,
  `verifikasi_bukti` MEDIUMTEXT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`implementation_id`) REFERENCES `implementations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`sasaran_mutu_id`) REFERENCES `sasaran_mutu`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`indicator_id`) REFERENCES `standard_indicators`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`audit_id`) REFERENCES `audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `findings`;
CREATE TABLE `findings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `audit_id` INT NOT NULL,
  `assessment_id` INT,
  `code` MEDIUMTEXT,
  `kategori` MEDIUMTEXT NOT NULL,
  `uraian` MEDIUMTEXT NOT NULL,
  `kriteria_acuan` MEDIUMTEXT,
  `bukti_objektif` MEDIUMTEXT,
  `status` VARCHAR(255) NOT NULL DEFAULT 'terbuka',
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assessment_id`) REFERENCES `audit_assessments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`audit_id`) REFERENCES `audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `signatures`;
CREATE TABLE `signatures` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `entity_type` VARCHAR(255) NOT NULL,
  `entity_id` INT NOT NULL,
  `user_id` INT,
  `peran` VARCHAR(255) NOT NULL,
  `nama` MEDIUMTEXT NOT NULL,
  `jabatan` MEDIUMTEXT,
  `image_data` MEDIUMTEXT NOT NULL,
  `signed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signatures_entity_type_entity_id_peran_user_id` (`entity_type`,`entity_id`,`peran`,`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `corrective_actions`;
CREATE TABLE `corrective_actions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `finding_id` INT NOT NULL,
  `analisis_penyebab` MEDIUMTEXT,
  `koreksi_sementara` MEDIUMTEXT,
  `rencana_tindakan` MEDIUMTEXT,
  `rencana_tanggal` MEDIUMTEXT,
  `penanggung_jawab` MEDIUMTEXT,
  `diisi_oleh` INT,
  `diisi_at` MEDIUMTEXT,
  `realisasi_tindakan` MEDIUMTEXT,
  `bukti_realisasi` MEDIUMTEXT,
  `tanggal_realisasi` MEDIUMTEXT,
  `realisasi_at` MEDIUMTEXT,
  `efektivitas` MEDIUMTEXT,
  `catatan_verifikasi` MEDIUMTEXT,
  `tanggal_penyelesaian` MEDIUMTEXT,
  `diverifikasi_oleh` INT,
  `diverifikasi_at` MEDIUMTEXT,
  `status` VARCHAR(255) NOT NULL DEFAULT 'menunggu_auditee',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_corrective_actions_finding_id` (`finding_id`),
  FOREIGN KEY (`diverifikasi_oleh`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`diisi_oleh`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`finding_id`) REFERENCES `findings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `rtm_meetings`;
CREATE TABLE `rtm_meetings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `period_id` INT NOT NULL,
  `nomor` MEDIUMTEXT,
  `judul` MEDIUMTEXT NOT NULL,
  `tanggal` MEDIUMTEXT,
  `waktu` MEDIUMTEXT,
  `tempat` MEDIUMTEXT,
  `pemimpin_id` INT,
  `notulis_id` INT,
  `status` VARCHAR(255) NOT NULL DEFAULT 'rencana',
  `catatan` MEDIUMTEXT,
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verifikator_id` INT,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`notulis_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`pemimpin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`period_id`) REFERENCES `periods`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `rtm_participants`;
CREATE TABLE `rtm_participants` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `rtm_id` INT NOT NULL,
  `user_id` INT,
  `nama` MEDIUMTEXT NOT NULL,
  `jabatan` MEDIUMTEXT,
  `hadir` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rtm_participants_rtm_id_user_id` (`rtm_id`,`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`rtm_id`) REFERENCES `rtm_meetings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `rtm_agenda`;
CREATE TABLE `rtm_agenda` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `rtm_id` INT NOT NULL,
  `agenda_key` VARCHAR(255) NOT NULL,
  `parent_key` MEDIUMTEXT,
  `judul` MEDIUMTEXT NOT NULL,
  `pembahasan` MEDIUMTEXT,
  `data_pendukung` MEDIUMTEXT,
  `kesimpulan` MEDIUMTEXT,
  `sort_order` INT NOT NULL DEFAULT 0,
  `pic` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rtm_agenda_rtm_id_agenda_key` (`rtm_id`,`agenda_key`),
  FOREIGN KEY (`rtm_id`) REFERENCES `rtm_meetings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `rtm_outputs`;
CREATE TABLE `rtm_outputs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `rtm_id` INT NOT NULL,
  `kategori` MEDIUMTEXT NOT NULL,
  `uraian` MEDIUMTEXT NOT NULL,
  `unit_id` INT,
  `penanggung_jawab` MEDIUMTEXT,
  `target_tanggal` MEDIUMTEXT,
  `status_tindak_lanjut` VARCHAR(255) NOT NULL DEFAULT 'direncanakan',
  `realisasi` MEDIUMTEXT,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`rtm_id`) REFERENCES `rtm_meetings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT,
  `aksi` MEDIUMTEXT NOT NULL,
  `entity` MEDIUMTEXT,
  `entity_id` INT,
  `keterangan` MEDIUMTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `indicator_assignments`;
CREATE TABLE `indicator_assignments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `indicator_id` INT NOT NULL,
  `unit_id` INT NOT NULL,
  `peran` VARCHAR(255) NOT NULL DEFAULT 'pelaksana',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_indicator_assignments_indicator_id_unit_id` (`indicator_id`,`unit_id`),
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`indicator_id`) REFERENCES `standard_indicators`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `refs`;
CREATE TABLE `refs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(255) NOT NULL,
  `name` MEDIUMTEXT NOT NULL,
  `version` MEDIUMTEXT,
  `publisher` MEDIUMTEXT,
  `effective_date` MEDIUMTEXT,
  `warna` MEDIUMTEXT,
  `keterangan` MEDIUMTEXT,
  `is_active` INT NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `jenjang` MEDIUMTEXT,
  `skor_min` DOUBLE,
  `skor_maks` DOUBLE,
  `skor_step` DOUBLE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refs_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ref_items`;
CREATE TABLE `ref_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ref_id` INT NOT NULL,
  `parent_id` INT,
  `code` VARCHAR(255) NOT NULL,
  `title` MEDIUMTEXT NOT NULL,
  `uraian` MEDIUMTEXT,
  `tipe` VARCHAR(255) NOT NULL DEFAULT 'butir',
  `level` INT NOT NULL DEFAULT 1,
  `bobot` DOUBLE,
  `wajib_petakan` INT NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `target` MEDIUMTEXT,
  `satuan` MEDIUMTEXT,
  `strategy` MEDIUMTEXT,
  `target_skor` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ref_items_ref_id_code` (`ref_id`,`code`),
  FOREIGN KEY (`parent_id`) REFERENCES `ref_items`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ref_id`) REFERENCES `refs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ref_map`;
CREATE TABLE `ref_map` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ref_item_id` INT NOT NULL,
  `standard_id` INT,
  `indicator_id` INT,
  `kekuatan` VARCHAR(255) NOT NULL DEFAULT 'utama',
  `catatan` MEDIUMTEXT,
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`indicator_id`) REFERENCES `standard_indicators`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`standard_id`) REFERENCES `standards`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ref_item_id`) REFERENCES `ref_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `standard_statements`;
CREATE TABLE `standard_statements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `standard_id` INT NOT NULL,
  `label` MEDIUMTEXT,
  `statement` MEDIUMTEXT NOT NULL,
  `rationale` MEDIUMTEXT,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`standard_id`) REFERENCES `standards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `auditor_certificates`;
CREATE TABLE `auditor_certificates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `jenis` MEDIUMTEXT NOT NULL,
  `nama` MEDIUMTEXT NOT NULL,
  `penyelenggara` MEDIUMTEXT,
  `nomor_sertifikat` MEDIUMTEXT,
  `tanggal_terbit` MEDIUMTEXT,
  `tanggal_kedaluwarsa` MEDIUMTEXT,
  `berkas_url` MEDIUMTEXT,
  `keterangan` MEDIUMTEXT,
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `spme_scores`;
CREATE TABLE `spme_scores` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `period_id` INT NOT NULL,
  `instrumen` VARCHAR(255) NOT NULL,
  `ref_item_id` INT NOT NULL,
  `skor` DOUBLE,
  `catatan` MEDIUMTEXT,
  `updated_by` INT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `capaian` DOUBLE,
  `bukti_url` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spme_scores_period_id_instrumen_ref_item_id` (`period_id`,`instrumen`,`ref_item_id`),
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`ref_item_id`) REFERENCES `ref_items`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`period_id`) REFERENCES `periods`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `spme_syarat`;
CREATE TABLE `spme_syarat` (
  `period_id` INT NOT NULL,
  `instrumen` VARCHAR(255) NOT NULL,
  `syarat_terakreditasi` INT,
  `unggul_tier` MEDIUMTEXT,
  `catatan` MEDIUMTEXT,
  `updated_by` INT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`period_id`,`instrumen`),
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`period_id`) REFERENCES `periods`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ranah_custom`;
CREATE TABLE `ranah_custom` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(255) NOT NULL,
  `label` MEDIUMTEXT NOT NULL,
  `pendek` MEDIUMTEXT NOT NULL,
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ranah_custom_code` (`code`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sasaran_mutu_rencana`;
CREATE TABLE `sasaran_mutu_rencana` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `sasaran_mutu_id` INT NOT NULL,
  `periode_ke` INT NOT NULL,
  `label` MEDIUMTEXT NOT NULL,
  `target_periode` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sasaran_mutu_rencana_sasaran_mutu_id_periode_ke` (`sasaran_mutu_id`,`periode_ke`),
  FOREIGN KEY (`sasaran_mutu_id`) REFERENCES `sasaran_mutu`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sasaran_mutu_capaian`;
CREATE TABLE `sasaran_mutu_capaian` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `sasaran_mutu_id` INT NOT NULL,
  `periode_ke` INT NOT NULL,
  `realisasi` MEDIUMTEXT,
  `capaian_nilai` DOUBLE,
  `bukti_uraian` MEDIUMTEXT,
  `bukti_url` MEDIUMTEXT,
  `kendala` MEDIUMTEXT,
  `status` VARCHAR(255) NOT NULL DEFAULT 'draft',
  `submitted_by` INT,
  `submitted_at` MEDIUMTEXT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `faktor_pendukung` MEDIUMTEXT,
  `capaian_teks` MEDIUMTEXT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sasaran_mutu_capaian_sasaran_mutu_id_periode_ke` (`sasaran_mutu_id`,`periode_ke`),
  FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`sasaran_mutu_id`) REFERENCES `sasaran_mutu`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `posts`;
CREATE TABLE `posts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(255) NOT NULL,
  `title` MEDIUMTEXT NOT NULL,
  `excerpt` MEDIUMTEXT,
  `content` MEDIUMTEXT NOT NULL,
  `cover_url` MEDIUMTEXT,
  `status` VARCHAR(255) NOT NULL DEFAULT 'draft',
  `published_at` MEDIUMTEXT,
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_posts_slug` (`slug`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `pages`;
CREATE TABLE `pages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(255) NOT NULL,
  `title` MEDIUMTEXT NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'draft',
  `sort_order` INT NOT NULL DEFAULT 0,
  `parent_id` INT,
  `external_url` MEDIUMTEXT,
  `created_by` INT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pages_slug` (`slug`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`parent_id`) REFERENCES `pages`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `idx_std_period` ON `standards` (`period_id`);
CREATE INDEX `idx_ind_std` ON `standard_indicators` (`standard_id`);
CREATE INDEX `idx_impl_unit` ON `implementations` (`unit_id`,`period_id`);
CREATE INDEX `idx_audit_unit` ON `audits` (`unit_id`,`period_id`);
CREATE INDEX `idx_find_audit` ON `findings` (`audit_id`);
CREATE INDEX `idx_sig_entity` ON `signatures` (`entity_type`,`entity_id`);
CREATE INDEX `idx_rtm_agenda` ON `rtm_agenda` (`rtm_id`);
CREATE INDEX `idx_rtm_output` ON `rtm_outputs` (`rtm_id`);
-- dilewati (indeks ekspresi): idx_ref_map_unik
CREATE INDEX `idx_std_ranah` ON `standards` (`period_id`,`ranah`(191),`kelompok`(191));
CREATE INDEX `idx_ia_ind` ON `indicator_assignments` (`indicator_id`);
CREATE INDEX `idx_ia_unit` ON `indicator_assignments` (`unit_id`);
CREATE INDEX `idx_refitem_ref` ON `ref_items` (`ref_id`,`parent_id`);
CREATE INDEX `idx_refmap_item` ON `ref_map` (`ref_item_id`);
CREATE INDEX `idx_refmap_std` ON `ref_map` (`standard_id`);
CREATE INDEX `idx_refmap_ind` ON `ref_map` (`indicator_id`);
CREATE INDEX `idx_ind_stmt` ON `standard_indicators` (`statement_id`);
CREATE INDEX `idx_ind_source` ON `standard_indicators` (`source_ref_item_id`);
CREATE INDEX `idx_stmt_std` ON `standard_statements` (`standard_id`);
CREATE INDEX `idx_refitem_target` ON `ref_items` (`ref_id`,`target`(191));
CREATE INDEX `idx_auditor_certificates_user` ON `auditor_certificates` (`user_id`);
CREATE INDEX `idx_spme_period` ON `spme_scores` (`period_id`,`instrumen`);
-- dilewati (indeks ekspresi): idx_audit_assessments_unik
CREATE INDEX `idx_assess_audit` ON `audit_assessments` (`audit_id`);
CREATE INDEX `idx_program_kerja_unit_period` ON `program_kerja` (`unit_id`,`period_id`);
CREATE INDEX `idx_sasaran_mutu_program` ON `sasaran_mutu` (`program_kerja_id`);
CREATE INDEX `idx_program_kerja_unit_tahun` ON `program_kerja` (`unit_id`,`tahun`);
CREATE INDEX `idx_posts_status` ON `posts` (`status`,`published_at`(191));
CREATE INDEX `idx_pages_status` ON `pages` (`status`,`sort_order`);
CREATE INDEX `idx_pages_parent` ON `pages` (`parent_id`);

SET FOREIGN_KEY_CHECKS = 1;
