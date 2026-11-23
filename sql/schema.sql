-- Схема БД: учёт заявок на ремонт техники
CREATE DATABASE IF NOT EXISTS repair_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE repair_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS request_events;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS requests;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  fio           VARCHAR(120) NOT NULL,
  phone         VARCHAR(20)  NOT NULL,
  login         VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('manager','technician','operator','customer') NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE requests (
  request_id      INT AUTO_INCREMENT PRIMARY KEY,
  start_date      DATE NOT NULL,
  tech_type       VARCHAR(60)  NOT NULL,
  tech_model      VARCHAR(160) NOT NULL,
  problem         TEXT NOT NULL,
  status          ENUM('new','in_progress','waiting_parts','ready') NOT NULL DEFAULT 'new',
  completion_date DATE NULL,
  repair_parts    TEXT NULL,
  master_id       INT NULL,
  client_id       INT NOT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_req_master FOREIGN KEY (master_id) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_req_client FOREIGN KEY (client_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX idx_req_status (status),
  INDEX idx_req_master (master_id),
  INDEX idx_req_client (client_id)
) ENGINE=InnoDB;

CREATE TABLE comments (
  comment_id INT AUTO_INCREMENT PRIMARY KEY,
  message    TEXT NOT NULL,
  master_id  INT NOT NULL,
  request_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_com_user FOREIGN KEY (master_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_com_req  FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NULL,
  request_id INT NULL,
  message    VARCHAR(255) NOT NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_req  FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE,
  INDEX idx_notif_user (user_id, is_read)
) ENGINE=InnoDB;

CREATE TABLE request_events (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  old_status VARCHAR(30) NOT NULL,
  new_status VARCHAR(30) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_event_req FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE
) ENGINE=InnoDB;

DELIMITER //

-- Новая заявка -> уведомление всем сотрудникам
CREATE TRIGGER trg_request_created AFTER INSERT ON requests
FOR EACH ROW
BEGIN
  INSERT INTO notifications (user_id, request_id, message)
  VALUES (NULL, NEW.request_id,
          CONCAT('Новая заявка №', NEW.request_id, ': ', NEW.tech_type, ' ', NEW.tech_model));
END//

-- Смена статуса -> запись в историю + уведомления клиенту и мастеру
CREATE TRIGGER trg_request_status AFTER UPDATE ON requests
FOR EACH ROW
BEGIN
  IF NEW.status <> OLD.status THEN
    INSERT INTO request_events (request_id, old_status, new_status)
    VALUES (NEW.request_id, OLD.status, NEW.status);

    INSERT INTO notifications (user_id, request_id, message)
    VALUES (NEW.client_id, NEW.request_id,
      CONCAT('Заявка №', NEW.request_id, ': ',
        CASE OLD.status
          WHEN 'new' THEN 'новая заявка'
          WHEN 'in_progress' THEN 'в процессе ремонта'
          WHEN 'waiting_parts' THEN 'ожидание комплектующих'
          WHEN 'ready' THEN 'готова к выдаче'
        END,
        ' → ',
        CASE NEW.status
          WHEN 'new' THEN 'новая заявка'
          WHEN 'in_progress' THEN 'в процессе ремонта'
          WHEN 'waiting_parts' THEN 'ожидание комплектующих'
          WHEN 'ready' THEN 'готова к выдаче'
        END));

    IF NEW.master_id IS NOT NULL THEN
      INSERT INTO notifications (user_id, request_id, message)
      VALUES (NEW.master_id, NEW.request_id,
              CONCAT('Заявка №', NEW.request_id, ': статус изменён'));
    END IF;
  END IF;
END//

-- Новый комментарий -> уведомление клиенту
CREATE TRIGGER trg_comment_created AFTER INSERT ON comments
FOR EACH ROW
BEGIN
  INSERT INTO notifications (user_id, request_id, message)
  SELECT r.client_id, NEW.request_id,
         CONCAT('Новый комментарий к заявке №', NEW.request_id)
  FROM requests r
  WHERE r.request_id = NEW.request_id;
END//

-- Статистика по типам техники за период (NULL = без ограничения)
CREATE PROCEDURE stats_by_type(IN date_from DATE, IN date_to DATE)
BEGIN
  SELECT tech_type,
         COUNT(*) AS total,
         SUM(status = 'ready') AS done,
         ROUND(AVG(CASE WHEN completion_date IS NOT NULL
                        THEN DATEDIFF(completion_date, start_date) END), 1) AS avg_days
  FROM requests
  WHERE (date_from IS NULL OR start_date >= date_from)
    AND (date_to   IS NULL OR start_date <= date_to)
  GROUP BY tech_type
  ORDER BY total DESC, tech_type;
END//

-- Статистика по мастерам за период
CREATE PROCEDURE stats_by_master(IN date_from DATE, IN date_to DATE)
BEGIN
  SELECT u.fio AS master,
         COUNT(r.request_id) AS total,
         SUM(r.status = 'ready') AS done
  FROM users u
  LEFT JOIN requests r
         ON r.master_id = u.user_id
        AND (date_from IS NULL OR r.start_date >= date_from)
        AND (date_to   IS NULL OR r.start_date <= date_to)
  WHERE u.role = 'technician'
  GROUP BY u.user_id, u.fio
  ORDER BY done DESC, total DESC, u.fio;
END//

DELIMITER ;
