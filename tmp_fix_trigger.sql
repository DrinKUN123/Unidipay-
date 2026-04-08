DROP TRIGGER IF EXISTS generate_receipt_on_complete;
DELIMITER $$
CREATE TRIGGER generate_receipt_on_complete
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
    IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
        INSERT INTO receipts (order_id, items, total)
        SELECT
            NEW.id,
            CONCAT(
                '[',
                IFNULL(GROUP_CONCAT(
                    JSON_OBJECT(
                        'name', oi.name,
                        'price', oi.price,
                        'quantity', oi.quantity,
                        'subtotal', oi.subtotal
                    ) SEPARATOR ','
                ), ''),
                ']'
            ),
            NEW.total
        FROM order_items oi
        WHERE oi.order_id = NEW.id;

        -- Set completed timestamp
        UPDATE orders SET completed_at = NOW() WHERE id = NEW.id;
    END IF;
END $$
DELIMITER ;
