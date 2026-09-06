-- Add soft-archive support for AI assistant conversations.
-- Archived conversations remain available in the database but are hidden from the active session list.

ALTER TABLE `chat_conversations`
  ADD COLUMN `archived_at` timestamp NULL DEFAULT NULL AFTER `updated_at`,
  ADD KEY `idx_chat_conv_archived` (`user_id`, `archived_at`);
