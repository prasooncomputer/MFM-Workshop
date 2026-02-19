CREATE DATABASE IF NOT EXISTS mfm_workshop;
USE mfm_workshop;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  sku VARCHAR(80) UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_process (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  process_name VARCHAR(120) NOT NULL,
  sequence_no INT NOT NULL,
  depends_on_sequence_no INT NULL,
  standard_time_minutes DECIMAL(10,2) NOT NULL,
  standard_output_rate DECIMAL(10,2) NOT NULL,
  can_parallel TINYINT(1) DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_process_sequence (product_id, sequence_no)
);

CREATE TABLE IF NOT EXISTS bom (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  material_name VARCHAR(120) NOT NULL,
  qty_per_unit DECIMAL(10,2) NOT NULL,
  unit VARCHAR(30) NOT NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  start_date DATETIME NOT NULL,
  priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
  status ENUM('planned','released','in_progress','completed') DEFAULT 'planned',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id)
);

CREATE TABLE IF NOT EXISTS tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_process_id INT NOT NULL,
  process_name VARCHAR(120) NOT NULL,
  target_quantity INT NOT NULL,
  completed_quantity INT DEFAULT 0,
  reject_quantity INT DEFAULT 0,
  workers_assigned INT DEFAULT 1,
  standard_time_minutes DECIMAL(10,2) NOT NULL,
  standard_output_rate DECIMAL(10,2) NOT NULL,
  can_parallel TINYINT(1) DEFAULT 0,
  planned_start DATETIME,
  planned_end DATETIME,
  actual_start DATETIME NULL,
  actual_end DATETIME NULL,
  status ENUM('pending','ready','in_progress','paused','completed','blocked','risk','delayed') DEFAULT 'pending',
  force_start DATETIME NULL,
  force_deadline DATETIME NULL,
  manual_lock TINYINT(1) DEFAULT 0,
  pause_flag TINYINT(1) DEFAULT 0,
  priority_override ENUM('low','normal','high','urgent') NULL,
  depends_on_task_id INT NULL,
  sequence_no INT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_process_id) REFERENCES product_process(id),
  FOREIGN KEY (depends_on_task_id) REFERENCES tasks(id)
);

CREATE TABLE IF NOT EXISTS logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  employee_name VARCHAR(120) NOT NULL,
  output_qty INT NOT NULL,
  reject_qty INT DEFAULT 0,
  hours_worked DECIMAL(6,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
