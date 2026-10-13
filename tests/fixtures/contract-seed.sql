--
-- PostgreSQL database dump
--

\restrict Vwn81lEBDQZqWHU9LZE8NbkX96ezA8xZoaiWwCeIEaTlax23O7ouP1ChWusjVk9

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: -
--

SET SESSION AUTHORIZATION DEFAULT;

ALTER TABLE public.users DISABLE TRIGGER ALL;

INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9001, 'TestUser', NULL, 'user', NULL, NULL, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9002, 'TestAdmin', NULL, 'admin', NULL, NULL, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9003, 'FreshUser', NULL, 'user', NULL, NULL, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9004, 'DiscordSynced', NULL, 'user', NULL, NULL, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9005, 'DiscordLinked', NULL, 'user', NULL, NULL, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9006, 'DiscordUnlinked', NULL, 'user', NULL, NULL, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9007, 'TestDeveloper', NULL, 'developer', NULL, NULL, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9008, 'TestMember', NULL, 'member', NULL, NULL, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9009, 'TestAssistant', NULL, 'assistant', NULL, NULL, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');


ALTER TABLE public.users ENABLE TRIGGER ALL;

--
-- Data for Name: appearances; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.appearances DISABLE TRIGGER ALL;

INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (1, 1, 'Twilight Sparkle', NULL, 'pony', '2026-10-13 15:46:23+02', false, NULL, NULL, '07b22cdd-960d-4ffb-a91d-ca2a3f2b4e12', NULL, '2026-10-13 15:46:23+02', NULL);
INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (3, NULL, 'Personal Test Pony', NULL, NULL, '2026-10-13 15:46:23+02', false, NULL, NULL, '1ecd8bdf-b733-4b34-a832-4c9f4e9cad7f', NULL, '2026-10-13 15:46:23+02', 9001);
INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (4, NULL, 'Private Test Pony', NULL, NULL, '2026-10-13 15:46:23+02', true, NULL, NULL, '0f0e0d0c-0b0a-4000-8000-00000000f004', NULL, '2026-10-13 15:46:23+02', 9001);
INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (2, 2, 'Deletable Test Pony', NULL, 'pony', '2026-10-13 15:46:23+02', false, NULL, NULL, '5ef3a60a-3c90-4285-affa-2ad583e3ed61', NULL, '2026-10-13 15:46:23+02', NULL);
INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (5, 2, 'Rainbow Dash', 'Fastest flyer in *Ponyville*', 'pony', '2026-10-13 15:46:23+02', false, NULL, 'Fastest flyer in <em>Ponyville</em>', 'a98a3e4b-9f43-4c88-ac14-c4e83d6e2172', NULL, '2026-10-13 15:46:23+02', NULL);
INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (6, 3, 'Fluttershy', NULL, 'pony', '2026-10-13 15:46:23+02', false, NULL, NULL, '2b9dfe88-58d9-43e5-8806-2cc7fd9efe26', NULL, '2026-10-13 15:46:23+02', NULL);
INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (7, 1, 'Sunset Shimmer', NULL, 'eqg', '2026-10-13 15:46:23+02', false, NULL, NULL, 'b3d365b1-8038-43c3-8059-e1d9f8a61332', NULL, '2026-10-13 15:46:23+02', NULL);


ALTER TABLE public.appearances ENABLE TRIGGER ALL;

--
-- Data for Name: blocked_emails; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.blocked_emails DISABLE TRIGGER ALL;



ALTER TABLE public.blocked_emails ENABLE TRIGGER ALL;

--
-- Data for Name: show; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.show DISABLE TRIGGER ALL;

INSERT INTO public.show (season, episode, parts, title, created_at, airs, no, score, notes, id, type, updated_at, posted_by) VALUES (1, 1, 1, 'Friendship is Magic, Part 1', '2026-10-13 15:46:23.016377+02', '2010-10-10 02:00:00+02', 1, 0, NULL, 1, 'episode', NULL, 9002);
INSERT INTO public.show (season, episode, parts, title, created_at, airs, no, score, notes, id, type, updated_at, posted_by) VALUES (NULL, NULL, 1, 'Equestria Girls', '2026-10-13 15:46:23.016377+02', '2013-06-16 02:00:00+02', 1, 0, NULL, 2, 'movie', NULL, 9002);
INSERT INTO public.show (season, episode, parts, title, created_at, airs, no, score, notes, id, type, updated_at, posted_by) VALUES (2, 1, 1, 'The Return of Harmony', '2026-10-13 15:46:23.016377+02', '2026-10-11 15:46:23+02', 2, 4.5, NULL, 3, 'episode', NULL, 9002);
INSERT INTO public.show (season, episode, parts, title, created_at, airs, no, score, notes, id, type, updated_at, posted_by) VALUES (2, 2, 1, 'Lesson Zero', '2026-10-13 15:46:23.016377+02', '2026-10-16 15:46:23+02', 3, 0, NULL, 4, 'episode', NULL, 9002);


ALTER TABLE public.show ENABLE TRIGGER ALL;

--
-- Data for Name: posts; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.posts DISABLE TRIGGER ALL;

INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (1, 'chr', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Seeded Test Request', '2026-10-13 15:46:23+02', NULL, NULL, false, NULL, false, 1, 9001, NULL);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (2, 'chr', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Deletable Test Request', '2026-10-13 15:46:23+02', NULL, NULL, false, NULL, false, 1, 9001, NULL);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (3, 'obj', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Reserved Test Request', '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02', 'dfin001', false, '2026-10-13 15:46:23+02', false, 1, 9001, 9002);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (4, 'bg', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Broken Test Request', '2026-10-13 15:46:23+02', NULL, NULL, false, NULL, true, 1, 9001, NULL);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (5, 'obj', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Broken UI Request', '2026-10-13 15:46:23+02', NULL, NULL, false, NULL, true, 1, 9001, NULL);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (6, 'chr', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Recently Reserved Request', '2026-10-03 15:46:23+02', '2026-10-11 15:46:23+02', NULL, false, NULL, false, 1, 9001, 9008);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (7, 'obj', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Overdue Reserved Request', '2026-10-03 15:46:23+02', '2026-09-13 15:46:23+02', NULL, false, NULL, false, 1, 9001, 9008);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (8, 'chr', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Reservation Without A Request', '2026-10-03 15:46:23+02', '2026-10-12 15:46:23+02', NULL, false, NULL, false, 1, NULL, 9008);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (9, 'chr', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Awaiting Approval Request', '2026-10-03 15:46:23+02', '2026-10-08 15:46:23+02', 'dfin008', false, '2026-10-12 15:46:23+02', false, 1, 9001, 9008);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (10, 'bg', '/img/blank-pixel.png', '/img/blank-pixel.png', 'Approved Request', '2026-10-03 15:46:23+02', '2026-10-07 15:46:23+02', 'dfin009', true, '2026-10-11 15:46:23+02', false, 1, 9001, 9008);


ALTER TABLE public.posts ENABLE TRIGGER ALL;

--
-- Data for Name: broken_posts; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.broken_posts DISABLE TRIGGER ALL;



ALTER TABLE public.broken_posts ENABLE TRIGGER ALL;

--
-- Data for Name: color_groups; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.color_groups DISABLE TRIGGER ALL;

INSERT INTO public.color_groups (id, appearance_id, label, "order") VALUES (1, 3, 'Personal Coat', 1);
INSERT INTO public.color_groups (id, appearance_id, label, "order") VALUES (2, 5, 'Coat', 1);
INSERT INTO public.color_groups (id, appearance_id, label, "order") VALUES (3, 5, 'Mane', 2);
INSERT INTO public.color_groups (id, appearance_id, label, "order") VALUES (4, 6, 'Coat', 1);
INSERT INTO public.color_groups (id, appearance_id, label, "order") VALUES (5, 7, 'Skin', 1);


ALTER TABLE public.color_groups ENABLE TRIGGER ALL;

--
-- Data for Name: colors; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.colors DISABLE TRIGGER ALL;

INSERT INTO public.colors (group_id, "order", label, hex, id) VALUES (1, 1, 'Personal Base', '#AA55CC', 1);
INSERT INTO public.colors (group_id, "order", label, hex, id) VALUES (2, 1, 'Fill', '#D19FE4', 2);
INSERT INTO public.colors (group_id, "order", label, hex, id) VALUES (2, 2, 'Outline', '#A06BBE', 3);
INSERT INTO public.colors (group_id, "order", label, hex, id) VALUES (3, 1, 'Base', '#273771', 4);
INSERT INTO public.colors (group_id, "order", label, hex, id) VALUES (3, 2, 'Stripe', '#EB5191', 5);
INSERT INTO public.colors (group_id, "order", label, hex, id) VALUES (4, 1, 'Fill', '#9BDBF5', 6);
INSERT INTO public.colors (group_id, "order", label, hex, id) VALUES (5, 1, 'Fill', '#F3B5CF', 7);


ALTER TABLE public.colors ENABLE TRIGGER ALL;

--
-- Data for Name: deviantart_users; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.deviantart_users DISABLE TRIGGER ALL;

INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009001', 'TestUser', '/img/blank-pixel.png', '2026-10-13 15:46:23+02', NULL, 9001, 'fake-access-token-user', 'fake-refresh-token-user', '2036-10-13 15:46:23+02', 'user');
INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009002', 'TestAdmin', '/img/blank-pixel.png', '2026-10-13 15:46:23+02', NULL, 9002, 'fake-access-token-admin', 'fake-refresh-token-admin', '2036-10-13 15:46:23+02', 'user');
INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009007', 'TestDeveloper', '/img/blank-pixel.png', '2026-10-13 15:46:23+02', NULL, 9007, 'fake-access-token-developer', 'fake-refresh-token-developer', '2036-10-13 15:46:23+02', 'user');
INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009003', 'FreshUser', '/img/blank-pixel.png', '2026-10-13 15:46:23+02', NULL, 9003, 'fake-access-token-fresh', 'fake-refresh-token-fresh', '2036-10-13 15:46:23+02', 'user');
INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009008', 'TestMember', '/img/blank-pixel.png', '2026-10-13 15:46:23+02', NULL, 9008, 'fake-access-token-member', 'fake-refresh-token-member', '2036-10-13 15:46:23+02', 'user');
INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009009', 'TestAssistant', '/img/blank-pixel.png', '2026-10-13 15:46:23+02', NULL, 9009, 'fake-access-token-assistant', 'fake-refresh-token-assistant', '2036-10-13 15:46:23+02', 'user');


ALTER TABLE public.deviantart_users ENABLE TRIGGER ALL;

--
-- Data for Name: cutiemarks; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.cutiemarks DISABLE TRIGGER ALL;

INSERT INTO public.cutiemarks (id, appearance_id, facing, favme, rotation, contributor_id, label) VALUES (900001, 1, 'left', NULL, 0, NULL, NULL);
INSERT INTO public.cutiemarks (id, appearance_id, facing, favme, rotation, contributor_id, label) VALUES (900002, 2, 'left', NULL, 0, NULL, NULL);


ALTER TABLE public.cutiemarks ENABLE TRIGGER ALL;

--
-- Data for Name: discord_members; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.discord_members DISABLE TRIGGER ALL;

INSERT INTO public.discord_members (id, username, discriminator, nick, avatar_hash, joined_at, access, refresh, scope, expires, last_synced, user_id, display_name) VALUES (900000000000009004, 'discordsynced', 0, NULL, NULL, NULL, 'fake-discord-access-a', 'fake-discord-refresh-a', 'identify', '2036-10-13 15:46:23+02', '2026-10-13 15:46:23+02', 9004, NULL);
INSERT INTO public.discord_members (id, username, discriminator, nick, avatar_hash, joined_at, access, refresh, scope, expires, last_synced, user_id, display_name) VALUES (900000000000009005, 'discordlinked', 0, NULL, NULL, NULL, 'fake-discord-access-b', 'fake-discord-refresh-b', 'identify', '2036-10-13 15:46:23+02', NULL, 9005, NULL);
INSERT INTO public.discord_members (id, username, discriminator, nick, avatar_hash, joined_at, access, refresh, scope, expires, last_synced, user_id, display_name) VALUES (900000000000009006, 'discordunlinked', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 9006, NULL);


ALTER TABLE public.discord_members ENABLE TRIGGER ALL;

--
-- Data for Name: email_verifications; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.email_verifications DISABLE TRIGGER ALL;



ALTER TABLE public.email_verifications ENABLE TRIGGER ALL;

--
-- Data for Name: events; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.events DISABLE TRIGGER ALL;

INSERT INTO public.events (id, name, entry_role, starts_at, ends_at, created_at, desc_src, desc_rend, max_entries, vote_role, result_favme, finalized_at, updated_at, added_by, finalized_by) VALUES (1, 'Test Coloring Event', 'user', '2026-10-12 15:46:23+02', '2026-10-20 15:46:23+02', '2026-10-13 15:46:23+02', 'Test event description.', '<p>Test event description.</p>', NULL, NULL, NULL, NULL, '2026-10-13 15:46:23+02', 9002, NULL);


ALTER TABLE public.events ENABLE TRIGGER ALL;

--
-- Data for Name: event_entries; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.event_entries DISABLE TRIGGER ALL;

INSERT INTO public.event_entries (id, event_id, prev_src, prev_full, prev_thumb, sub_prov, sub_id, created_at, title, updated_at, submitted_by) VALUES (1, 1, NULL, NULL, NULL, 'fav.me', 'd1b2c3d', '2026-10-13 15:46:23+02', 'Seeded Entry', '2026-10-13 15:46:23+02', 9001);
INSERT INTO public.event_entries (id, event_id, prev_src, prev_full, prev_thumb, sub_prov, sub_id, created_at, title, updated_at, submitted_by) VALUES (2, 1, NULL, NULL, NULL, 'fav.me', 'd1b2c3e', '2026-10-13 15:46:23+02', 'Admin Entry', '2026-10-13 15:46:23+02', 9002);
INSERT INTO public.event_entries (id, event_id, prev_src, prev_full, prev_thumb, sub_prov, sub_id, created_at, title, updated_at, submitted_by) VALUES (3, 1, NULL, NULL, NULL, 'fav.me', 'd1b2c3f', '2026-10-13 15:46:23+02', 'Doomed Entry', '2026-10-13 15:46:23+02', 9001);
INSERT INTO public.event_entries (id, event_id, prev_src, prev_full, prev_thumb, sub_prov, sub_id, created_at, title, updated_at, submitted_by) VALUES (4, 1, NULL, NULL, NULL, 'fav.me', 'd1b2c3g', '2026-10-13 15:46:23+02', 'Withdrawn Entry', '2026-10-13 15:46:23+02', 9001);


ALTER TABLE public.event_entries ENABLE TRIGGER ALL;

--
-- Data for Name: failed_auth_attempts; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.failed_auth_attempts DISABLE TRIGGER ALL;



ALTER TABLE public.failed_auth_attempts ENABLE TRIGGER ALL;

--
-- Data for Name: legacy_post_mappings; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.legacy_post_mappings DISABLE TRIGGER ALL;



ALTER TABLE public.legacy_post_mappings ENABLE TRIGGER ALL;

--
-- Data for Name: locked_posts; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.locked_posts DISABLE TRIGGER ALL;

INSERT INTO public.locked_posts (id, post_id, created_at, updated_at, user_id) VALUES (1, 10, '2026-10-12 15:46:23+02', '2026-10-12 15:46:23+02', 9002);


ALTER TABLE public.locked_posts ENABLE TRIGGER ALL;

--
-- Data for Name: logs; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.logs DISABLE TRIGGER ALL;

INSERT INTO public.logs (id, entry_type, created_at, ip, data, updated_at, initiator) VALUES (1, 'rolechange', '2026-10-13 15:46:23+02', '127.0.0.1', '{"target": 9001, "newrole": "member", "oldrole": "user"}', NULL, 9002);


ALTER TABLE public.logs ENABLE TRIGGER ALL;

--
-- Data for Name: major_changes; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.major_changes DISABLE TRIGGER ALL;

INSERT INTO public.major_changes (id, appearance_id, reason, created_at, updated_at, user_id) VALUES (1, 1, 'Seeded older major change', '2026-10-11 15:46:23+02', '2026-10-11 15:46:23+02', 9002);
INSERT INTO public.major_changes (id, appearance_id, reason, created_at, updated_at, user_id) VALUES (2, 1, 'Seeded newest major change', '2026-10-12 15:46:23+02', '2026-10-12 15:46:23+02', 9002);


ALTER TABLE public.major_changes ENABLE TRIGGER ALL;

--
-- Data for Name: notices; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.notices DISABLE TRIGGER ALL;

INSERT INTO public.notices (message_html, type, created_at, updated_at, hide_after, id, posted_by) VALUES ('Seeded notice: the club meets on Fridays.', 'info', '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02', '2036-10-13 15:46:23+02', 1, 9002);


ALTER TABLE public.notices ENABLE TRIGGER ALL;

--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.notifications DISABLE TRIGGER ALL;

INSERT INTO public.notifications (id, type, data, created_at, read_at, updated_at, recipient_id) VALUES (1, 'post-approved', '{"id": 1, "type": "request"}', '2026-10-13 15:46:23.016377+02', NULL, NULL, 9001);
INSERT INTO public.notifications (id, type, data, created_at, read_at, updated_at, recipient_id) VALUES (2, 'post-approved', '{"id": 1, "type": "request"}', '2026-10-13 15:46:23.016377+02', NULL, NULL, 9001);
INSERT INTO public.notifications (id, type, data, created_at, read_at, updated_at, recipient_id) VALUES (3, 'post-approved', '{"id": 1, "type": "request"}', '2026-10-13 15:46:23.016377+02', NULL, NULL, 9002);


ALTER TABLE public.notifications ENABLE TRIGGER ALL;

--
-- Data for Name: pcg_point_grants; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.pcg_point_grants DISABLE TRIGGER ALL;

INSERT INTO public.pcg_point_grants (id, amount, comment, created_at, updated_at, receiver_id, sender_id) VALUES (1, 5, 'Seeded contract test grant', '2026-10-13 15:46:23+02', NULL, 9001, 9002);


ALTER TABLE public.pcg_point_grants ENABLE TRIGGER ALL;

--
-- Data for Name: pcg_slot_history; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.pcg_slot_history DISABLE TRIGGER ALL;



ALTER TABLE public.pcg_slot_history ENABLE TRIGGER ALL;

--
-- Data for Name: pinned_appearances; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.pinned_appearances DISABLE TRIGGER ALL;

INSERT INTO public.pinned_appearances (id, appearance_id, guide, "order", created_at, updated_at) VALUES (1, 5, 'pony', 1, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');


ALTER TABLE public.pinned_appearances ENABLE TRIGGER ALL;

--
-- Data for Name: previous_usernames; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.previous_usernames DISABLE TRIGGER ALL;



ALTER TABLE public.previous_usernames ENABLE TRIGGER ALL;

--
-- Data for Name: related_appearances; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.related_appearances DISABLE TRIGGER ALL;

INSERT INTO public.related_appearances (source_id, target_id) VALUES (1, 5);
INSERT INTO public.related_appearances (source_id, target_id) VALUES (5, 1);


ALTER TABLE public.related_appearances ENABLE TRIGGER ALL;

--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.sessions DISABLE TRIGGER ALL;

INSERT INTO public.sessions (platform, browser_name, browser_ver, user_agent, token, created, last_visit, data, updating, id, user_id) VALUES ('Linux', 'Firefox', '120.0', 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'e478c7cde773ba99e591611dc146d0da876019fdbdf4801982ccfedd829e5033', '2026-10-10 15:46:23+02', '2026-10-12 15:46:23+02', NULL, false, 1, 9001);


ALTER TABLE public.sessions ENABLE TRIGGER ALL;

--
-- Data for Name: settings; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.settings DISABLE TRIGGER ALL;

INSERT INTO public.settings (name, val, id, "group", created_at, updated_at) VALUES ('about_reservations', '<p>People usually get excited whenever a new episode comes out, and start making vectors of any pose/object/etc. that they found hilarious/interesting enough. It often results in various people unnecessarily doing the very same thing. Vector Reservations can help organize our efforts by listing who''s working on what and to reduce the number of duplicates.</p>', 1, 'default', '2026-10-13 15:46:23.004943+02', NULL);
INSERT INTO public.settings (name, val, id, "group", created_at, updated_at) VALUES ('reservation_rules', '<ol><li>You MUST have an image to make a reservation! For the best quality, get your references from the episode in 1080p.</li>
	<li>Making a reservation does NOT forbid other people from working on a pose anyway. It is only information that you are working on it, so other people can coordinate to avoid doing the same thing twice.</li>
	<li>There are no time limits, but remember that the longer you wait, the greater the chance that someone might take your pose anyway. It''s generally advised to finish your reservations before a new episode comes out.</li>
	<li>The current limit for reservations is 4 at any given time. You can reserve more after completing or cancelling any previous reservations.</li>
	<li>Please remember that <strong>you have to be a member of the group in order to make a reservation</strong>. The idea is to add the finished vector to our gallery, so it has to meet all of our quality requirements.</li>
</ol>', 2, 'default', '2026-10-13 15:46:23.004943+02', NULL);
INSERT INTO public.settings (name, val, id, "group", created_at, updated_at) VALUES ('dev_role_label', 'staff', 3, 'default', '2026-10-13 15:46:23.004943+02', NULL);


ALTER TABLE public.settings ENABLE TRIGGER ALL;

--
-- Data for Name: show_appearances; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.show_appearances DISABLE TRIGGER ALL;

INSERT INTO public.show_appearances (show_id, appearance_id, id) VALUES (3, 5, 1);
INSERT INTO public.show_appearances (show_id, appearance_id, id) VALUES (3, 6, 2);


ALTER TABLE public.show_appearances ENABLE TRIGGER ALL;

--
-- Data for Name: show_votes; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.show_votes DISABLE TRIGGER ALL;

INSERT INTO public.show_votes (vote, show_id, id, user_id) VALUES (5, 3, 1, 9001);
INSERT INTO public.show_votes (vote, show_id, id, user_id) VALUES (4, 3, 2, 9002);


ALTER TABLE public.show_votes ENABLE TRIGGER ALL;

--
-- Data for Name: tags; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.tags DISABLE TRIGGER ALL;

INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (1, 'twilight sparkle', 'The main character', 'char', 1, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (2, 'rainbow dash', NULL, 'char', 1, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (3, 'unicorn', NULL, 'spec', 1, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (4, 'pegasus', NULL, 'spec', 2, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (5, 'female', NULL, 'gen', 3, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (6, 'saddlebags', NULL, 'app', 0, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (7, 'mane six', 'The six main characters', 'cat', 2, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (8, 'nightmare', NULL, 'warn', 0, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (9, 'untyped tag', NULL, NULL, 0, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (10, 'twily', NULL, NULL, 0, 1, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');
INSERT INTO public.tags (id, name, title, type, uses, synonym_of, created_at, updated_at) VALUES (11, 'stale count', NULL, NULL, 7, NULL, '2026-10-13 15:46:23+02', '2026-10-13 15:46:23+02');


ALTER TABLE public.tags ENABLE TRIGGER ALL;

--
-- Data for Name: tag_changes; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.tag_changes DISABLE TRIGGER ALL;



ALTER TABLE public.tag_changes ENABLE TRIGGER ALL;

--
-- Data for Name: tagged; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.tagged DISABLE TRIGGER ALL;

INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (1, 1, 1);
INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (3, 1, 2);
INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (5, 1, 3);
INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (7, 1, 4);
INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (2, 5, 5);
INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (4, 5, 6);
INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (5, 5, 7);
INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (7, 5, 8);
INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (4, 6, 9);
INSERT INTO public.tagged (tag_id, appearance_id, id) VALUES (5, 6, 10);


ALTER TABLE public.tagged ENABLE TRIGGER ALL;

--
-- Data for Name: useful_links; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.useful_links DISABLE TRIGGER ALL;

INSERT INTO public.useful_links (id, url, label, title, minrole, "order") VALUES (1, '/cg', 'Color Guide', 'Everything about the colors', 'user', 1);
INSERT INTO public.useful_links (id, url, label, title, minrole, "order") VALUES (2, '/admin', 'Admin area', 'For the staff', 'staff', 2);


ALTER TABLE public.useful_links ENABLE TRIGGER ALL;

--
-- Data for Name: user_prefs; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.user_prefs DISABLE TRIGGER ALL;



ALTER TABLE public.user_prefs ENABLE TRIGGER ALL;

--
-- Name: appearances_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.appearances_id_seq', 900100, true);


--
-- Name: blocked_emails_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.blocked_emails_id_seq', 1, false);


--
-- Name: broken_posts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.broken_posts_id_seq', 1, false);


--
-- Name: color_groups_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.color_groups_id_seq', 5, true);


--
-- Name: colors_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.colors_id_seq', 7, true);


--
-- Name: cutiemarks_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.cutiemarks_id_seq', 900002, true);


--
-- Name: email_verifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.email_verifications_id_seq', 1, false);


--
-- Name: event_entries_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.event_entries_id_seq', 4, true);


--
-- Name: events_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.events_id_seq', 1, true);


--
-- Name: failed_auth_attempts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.failed_auth_attempts_id_seq', 1, false);


--
-- Name: legacy_post_mappings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.legacy_post_mappings_id_seq', 1, false);


--
-- Name: locked_posts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.locked_posts_id_seq', 1, true);


--
-- Name: logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.logs_id_seq', 1, true);


--
-- Name: major_changes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.major_changes_id_seq', 2, true);


--
-- Name: notices_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.notices_id_seq', 1, true);


--
-- Name: notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.notifications_id_seq', 3, true);


--
-- Name: pcg_point_grants_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.pcg_point_grants_id_seq', 1, true);


--
-- Name: pcg_slot_history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.pcg_slot_history_id_seq', 1, false);


--
-- Name: pinned_appearances_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.pinned_appearances_id_seq', 1, true);


--
-- Name: posts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.posts_id_seq', 10, true);


--
-- Name: previous_usernames_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.previous_usernames_id_seq', 1, false);


--
-- Name: sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.sessions_id_seq', 1, true);


--
-- Name: settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.settings_id_seq', 3, true);


--
-- Name: show_appearances_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.show_appearances_id_seq', 2, true);


--
-- Name: show_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.show_id_seq', 4, true);


--
-- Name: show_votes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.show_votes_id_seq', 2, true);


--
-- Name: tag_changes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tag_changes_id_seq', 1, false);


--
-- Name: tagged_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tagged_id_seq', 10, true);


--
-- Name: tags_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tags_id_seq', 11, true);


--
-- Name: useful_links_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.useful_links_id_seq', 2, true);


--
-- Name: user_prefs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.user_prefs_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.users_id_seq', 9009, true);


--
-- PostgreSQL database dump complete
--

\unrestrict Vwn81lEBDQZqWHU9LZE8NbkX96ezA8xZoaiWwCeIEaTlax23O7ouP1ChWusjVk9

