-- Repair legacy nutritionist event IDs and enable generated IDs for new events.

SET @next_event_id := (SELECT COALESCE(MAX(id), 0) + 1 FROM nutritionist_events);

UPDATE nutritionist_events
SET id = @next_event_id
WHERE id = 0;

ALTER TABLE nutritionist_events
    MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT;
