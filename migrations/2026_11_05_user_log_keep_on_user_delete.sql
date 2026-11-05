-- Brisanje korisnika zadržava njegov user_log: user_id postaje NULL umjesto da FK blokira brisanje.
ALTER TABLE `user_log`
  DROP FOREIGN KEY `user_log_ibfk_1`,
  MODIFY `user_id` INT(10) UNSIGNED NULL;

ALTER TABLE `user_log`
  ADD CONSTRAINT `user_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
