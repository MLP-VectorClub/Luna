--
-- PostgreSQL database dump
--

\restrict NnqdTgHCqAlLng4hnyKoEe323XeCGezvwzAhlaqwykpKsyKhzVzriyJ0PzWl9mg

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

INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9001, 'TestUser', NULL, 'user', NULL, NULL, NULL, '2026-10-02 10:52:53+02', '2026-10-02 10:52:53+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9002, 'TestAdmin', NULL, 'admin', NULL, NULL, NULL, '2026-10-02 10:52:53+02', '2026-10-02 10:52:53+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9003, 'FreshUser', NULL, 'user', NULL, NULL, NULL, '2026-10-02 10:52:53+02', '2026-10-02 10:52:53+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9004, 'DiscordSynced', NULL, 'user', NULL, NULL, NULL, '2026-10-02 10:52:53+02', '2026-10-02 10:52:53+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9005, 'DiscordLinked', NULL, 'user', NULL, NULL, NULL, '2026-10-02 10:52:53+02', '2026-10-02 10:52:53+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9006, 'DiscordUnlinked', NULL, 'user', NULL, NULL, NULL, '2026-10-02 10:52:53+02', '2026-10-02 10:52:53+02');
INSERT INTO public.users (id, name, email, role, email_verified_at, password, remember_token, created_at, updated_at) VALUES (9007, 'TestDeveloper', NULL, 'developer', NULL, NULL, NULL, '2026-10-02 10:52:53+02', '2026-10-02 10:52:53+02');


ALTER TABLE public.users ENABLE TRIGGER ALL;

--
-- Data for Name: appearances; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.appearances DISABLE TRIGGER ALL;

INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (1, 1, 'Twilight Sparkle', NULL, 'pony', '2026-10-02 10:52:53+02', false, NULL, NULL, '6a4bff0c-5dd8-48f7-ba74-8f0d58102ee0', NULL, '2026-10-02 10:52:53+02', NULL);
INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (3, NULL, 'Personal Test Pony', NULL, NULL, '2026-10-02 10:52:53+02', false, NULL, NULL, '3c989835-8a41-4616-aa6b-66ea3f4804e7', NULL, '2026-10-02 10:52:53+02', 9001);
INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (4, NULL, 'Private Test Pony', NULL, NULL, '2026-10-02 10:52:53+02', true, NULL, NULL, '0f0e0d0c-0b0a-4000-8000-00000000f004', NULL, '2026-10-02 10:52:53+02', 9001);
INSERT INTO public.appearances (id, "order", label, notes_src, guide, created_at, private, last_cleared, notes_rend, token, sprite_hash, updated_at, owner_id) VALUES (2, 2, 'Deletable Test Pony', NULL, 'pony', '2026-10-02 10:52:53+02', false, NULL, NULL, '2d48aa5c-96f8-454b-931f-c47539a0fe8d', NULL, '2026-10-02 10:52:53+02', NULL);


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

INSERT INTO public.show (season, episode, parts, title, created_at, airs, no, score, notes, id, type, updated_at, posted_by) VALUES (1, 1, 1, 'Friendship is Magic, Part 1', '2026-10-02 10:52:53.724008+02', '2010-10-10 02:00:00+02', 1, 0, NULL, 1, 'episode', NULL, 9002);
INSERT INTO public.show (season, episode, parts, title, created_at, airs, no, score, notes, id, type, updated_at, posted_by) VALUES (NULL, NULL, 1, 'Equestria Girls', '2026-10-02 10:52:53.724008+02', '2013-06-16 02:00:00+02', 1, 0, NULL, 2, 'movie', NULL, 9002);


ALTER TABLE public.show ENABLE TRIGGER ALL;

--
-- Data for Name: posts; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.posts DISABLE TRIGGER ALL;

INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (1, 'chr', 'http://127.0.0.1:8765/img/blank-pixel.png', 'http://127.0.0.1:8765/img/blank-pixel.png', 'Seeded Test Request', '2026-10-02 10:52:53+02', NULL, NULL, false, NULL, false, 1, 9001, NULL);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (2, 'chr', 'http://127.0.0.1:8765/img/blank-pixel.png', 'http://127.0.0.1:8765/img/blank-pixel.png', 'Deletable Test Request', '2026-10-02 10:52:53+02', NULL, NULL, false, NULL, false, 1, 9001, NULL);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (3, 'obj', 'http://127.0.0.1:8765/img/blank-pixel.png', 'http://127.0.0.1:8765/img/blank-pixel.png', 'Reserved Test Request', '2026-10-02 10:52:53+02', '2026-10-02 10:52:53+02', 'dfin001', false, '2026-10-02 10:52:53+02', false, 1, 9001, 9002);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (4, 'bg', 'http://127.0.0.1:8765/img/blank-pixel.png', 'http://127.0.0.1:8765/img/blank-pixel.png', 'Broken Test Request', '2026-10-02 10:52:53+02', NULL, NULL, false, NULL, true, 1, 9001, NULL);
INSERT INTO public.posts (id, type, preview, fullsize, label, requested_at, reserved_at, deviation_id, lock, finished_at, broken, show_id, requested_by, reserved_by) VALUES (5, 'obj', 'http://127.0.0.1:8765/img/blank-pixel.png', 'http://127.0.0.1:8765/img/blank-pixel.png', 'Broken UI Request', '2026-10-02 10:52:53+02', NULL, NULL, false, NULL, true, 1, 9001, NULL);


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


ALTER TABLE public.color_groups ENABLE TRIGGER ALL;

--
-- Data for Name: colors; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.colors DISABLE TRIGGER ALL;

INSERT INTO public.colors (group_id, "order", label, hex, id) VALUES (1, 1, 'Personal Base', '#AA55CC', 1);


ALTER TABLE public.colors ENABLE TRIGGER ALL;

--
-- Data for Name: deviantart_users; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.deviantart_users DISABLE TRIGGER ALL;

INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009001', 'TestUser', 'http://127.0.0.1:8765/img/blank-pixel.png', '2026-10-02 10:52:53+02', NULL, 9001, 'fake-access-token-user', 'fake-refresh-token-user', '2036-10-02 10:52:53+02', 'user');
INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009002', 'TestAdmin', 'http://127.0.0.1:8765/img/blank-pixel.png', '2026-10-02 10:52:53+02', NULL, 9002, 'fake-access-token-admin', 'fake-refresh-token-admin', '2036-10-02 10:52:53+02', 'user');
INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009007', 'TestDeveloper', 'http://127.0.0.1:8765/img/blank-pixel.png', '2026-10-02 10:52:53+02', NULL, 9007, 'fake-access-token-developer', 'fake-refresh-token-developer', '2036-10-02 10:52:53+02', 'user');
INSERT INTO public.deviantart_users (id, name, avatar_url, created_at, updated_at, user_id, access, refresh, access_expires, scope) VALUES ('0f0e0d0c-0b0a-4000-8000-000000009003', 'FreshUser', 'http://127.0.0.1:8765/img/blank-pixel.png', '2026-10-02 10:52:53+02', NULL, 9003, 'fake-access-token-fresh', 'fake-refresh-token-fresh', '2036-10-02 10:52:53+02', 'user');


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

INSERT INTO public.discord_members (id, username, discriminator, nick, avatar_hash, joined_at, access, refresh, scope, expires, last_synced, user_id, display_name) VALUES (900000000000009004, 'discordsynced', 0, NULL, NULL, NULL, 'fake-discord-access-a', 'fake-discord-refresh-a', 'identify', '2036-10-02 10:52:53+02', '2026-10-02 10:52:53+02', 9004, NULL);
INSERT INTO public.discord_members (id, username, discriminator, nick, avatar_hash, joined_at, access, refresh, scope, expires, last_synced, user_id, display_name) VALUES (900000000000009005, 'discordlinked', 0, NULL, NULL, NULL, 'fake-discord-access-b', 'fake-discord-refresh-b', 'identify', '2036-10-02 10:52:53+02', NULL, 9005, NULL);
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

INSERT INTO public.events (id, name, entry_role, starts_at, ends_at, created_at, desc_src, desc_rend, max_entries, vote_role, result_favme, finalized_at, updated_at, added_by, finalized_by) VALUES (1, 'Test Coloring Event', 'user', '2026-10-01 10:52:53+02', '2026-10-09 10:52:53+02', '2026-10-02 10:52:53+02', 'Test event description.', '<p>Test event description.</p>', NULL, NULL, NULL, NULL, '2026-10-02 10:52:53+02', 9002, NULL);


ALTER TABLE public.events ENABLE TRIGGER ALL;

--
-- Data for Name: event_entries; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.event_entries DISABLE TRIGGER ALL;

INSERT INTO public.event_entries (id, event_id, prev_src, prev_full, prev_thumb, sub_prov, sub_id, created_at, title, updated_at, submitted_by) VALUES (1, 1, NULL, NULL, NULL, 'fav.me', 'd1b2c3d', '2026-10-02 10:52:53+02', 'Seeded Entry', '2026-10-02 10:52:53+02', 9001);
INSERT INTO public.event_entries (id, event_id, prev_src, prev_full, prev_thumb, sub_prov, sub_id, created_at, title, updated_at, submitted_by) VALUES (2, 1, NULL, NULL, NULL, 'fav.me', 'd1b2c3e', '2026-10-02 10:52:53+02', 'Admin Entry', '2026-10-02 10:52:53+02', 9002);
INSERT INTO public.event_entries (id, event_id, prev_src, prev_full, prev_thumb, sub_prov, sub_id, created_at, title, updated_at, submitted_by) VALUES (3, 1, NULL, NULL, NULL, 'fav.me', 'd1b2c3f', '2026-10-02 10:52:53+02', 'Doomed Entry', '2026-10-02 10:52:53+02', 9001);
INSERT INTO public.event_entries (id, event_id, prev_src, prev_full, prev_thumb, sub_prov, sub_id, created_at, title, updated_at, submitted_by) VALUES (4, 1, NULL, NULL, NULL, 'fav.me', 'd1b2c3g', '2026-10-02 10:52:53+02', 'Withdrawn Entry', '2026-10-02 10:52:53+02', 9001);


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



ALTER TABLE public.locked_posts ENABLE TRIGGER ALL;

--
-- Data for Name: logs; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.logs DISABLE TRIGGER ALL;

INSERT INTO public.logs (id, entry_type, created_at, ip, data, updated_at, initiator) VALUES (1, 'rolechange', '2026-10-02 10:52:53+02', '127.0.0.1', '{"target": 9001, "newrole": "member", "oldrole": "user"}', NULL, 9002);


ALTER TABLE public.logs ENABLE TRIGGER ALL;

--
-- Data for Name: major_changes; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.major_changes DISABLE TRIGGER ALL;

INSERT INTO public.major_changes (id, appearance_id, reason, created_at, updated_at, user_id) VALUES (1, 1, 'Seeded older major change', '2026-09-30 10:52:53+02', '2026-09-30 10:52:53+02', 9002);
INSERT INTO public.major_changes (id, appearance_id, reason, created_at, updated_at, user_id) VALUES (2, 1, 'Seeded newest major change', '2026-10-01 10:52:53+02', '2026-10-01 10:52:53+02', 9002);


ALTER TABLE public.major_changes ENABLE TRIGGER ALL;

--
-- Data for Name: notices; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.notices DISABLE TRIGGER ALL;



ALTER TABLE public.notices ENABLE TRIGGER ALL;

--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.notifications DISABLE TRIGGER ALL;

INSERT INTO public.notifications (id, type, data, created_at, read_at, updated_at, recipient_id) VALUES (1, 'post-approved', '{"id": 1, "type": "request"}', '2026-10-02 10:52:53.724008+02', NULL, NULL, 9001);
INSERT INTO public.notifications (id, type, data, created_at, read_at, updated_at, recipient_id) VALUES (2, 'post-approved', '{"id": 1, "type": "request"}', '2026-10-02 10:52:53.724008+02', NULL, NULL, 9001);
INSERT INTO public.notifications (id, type, data, created_at, read_at, updated_at, recipient_id) VALUES (3, 'post-approved', '{"id": 1, "type": "request"}', '2026-10-02 10:52:53.724008+02', NULL, NULL, 9002);


ALTER TABLE public.notifications ENABLE TRIGGER ALL;

--
-- Data for Name: pcg_point_grants; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.pcg_point_grants DISABLE TRIGGER ALL;

INSERT INTO public.pcg_point_grants (id, amount, comment, created_at, updated_at, receiver_id, sender_id) VALUES (1, 5, 'Seeded contract test grant', '2026-10-02 10:52:53+02', NULL, 9001, 9002);


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



ALTER TABLE public.related_appearances ENABLE TRIGGER ALL;

--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.sessions DISABLE TRIGGER ALL;



ALTER TABLE public.sessions ENABLE TRIGGER ALL;

--
-- Data for Name: settings; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.settings DISABLE TRIGGER ALL;

INSERT INTO public.settings (name, val, id, "group", created_at, updated_at) VALUES ('about_reservations', '<p>People usually get excited whenever a new episode comes out, and start making vectors of any pose/object/etc. that they found hilarious/interesting enough. It often results in various people unnecessarily doing the very same thing. Vector Reservations can help organize our efforts by listing who''s working on what and to reduce the number of duplicates.</p>', 1, 'default', '2026-10-02 10:52:53.712632+02', NULL);
INSERT INTO public.settings (name, val, id, "group", created_at, updated_at) VALUES ('reservation_rules', '<ol><li>You MUST have an image to make a reservation! For the best quality, get your references from the episode in 1080p.</li>
	<li>Making a reservation does NOT forbid other people from working on a pose anyway. It is only information that you are working on it, so other people can coordinate to avoid doing the same thing twice.</li>
	<li>There are no time limits, but remember that the longer you wait, the greater the chance that someone might take your pose anyway. It''s generally advised to finish your reservations before a new episode comes out.</li>
	<li>The current limit for reservations is 4 at any given time. You can reserve more after completing or cancelling any previous reservations.</li>
	<li>Please remember that <strong>you have to be a member of the group in order to make a reservation</strong>. The idea is to add the finished vector to our gallery, so it has to meet all of our quality requirements.</li>
</ol>', 2, 'default', '2026-10-02 10:52:53.712632+02', NULL);
INSERT INTO public.settings (name, val, id, "group", created_at, updated_at) VALUES ('dev_role_label', 'staff', 3, 'default', '2026-10-02 10:52:53.712632+02', NULL);


ALTER TABLE public.settings ENABLE TRIGGER ALL;

--
-- Data for Name: show_appearances; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.show_appearances DISABLE TRIGGER ALL;



ALTER TABLE public.show_appearances ENABLE TRIGGER ALL;

--
-- Data for Name: show_votes; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.show_votes DISABLE TRIGGER ALL;



ALTER TABLE public.show_votes ENABLE TRIGGER ALL;

--
-- Data for Name: tags; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.tags DISABLE TRIGGER ALL;



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



ALTER TABLE public.tagged ENABLE TRIGGER ALL;

--
-- Data for Name: useful_links; Type: TABLE DATA; Schema: public; Owner: -
--

ALTER TABLE public.useful_links DISABLE TRIGGER ALL;



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

SELECT pg_catalog.setval('public.color_groups_id_seq', 1, true);


--
-- Name: colors_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.colors_id_seq', 1, true);


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

SELECT pg_catalog.setval('public.locked_posts_id_seq', 1, false);


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

SELECT pg_catalog.setval('public.notices_id_seq', 1, false);


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

SELECT pg_catalog.setval('public.pinned_appearances_id_seq', 1, false);


--
-- Name: posts_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.posts_id_seq', 5, true);


--
-- Name: previous_usernames_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.previous_usernames_id_seq', 1, false);


--
-- Name: sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.sessions_id_seq', 1, false);


--
-- Name: settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.settings_id_seq', 3, true);


--
-- Name: show_appearances_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.show_appearances_id_seq', 1, false);


--
-- Name: show_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.show_id_seq', 2, true);


--
-- Name: show_votes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.show_votes_id_seq', 1, false);


--
-- Name: tag_changes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tag_changes_id_seq', 1, false);


--
-- Name: tagged_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tagged_id_seq', 1, false);


--
-- Name: tags_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tags_id_seq', 1, false);


--
-- Name: useful_links_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.useful_links_id_seq', 1, false);


--
-- Name: user_prefs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.user_prefs_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.users_id_seq', 9007, true);


--
-- PostgreSQL database dump complete
--

\unrestrict NnqdTgHCqAlLng4hnyKoEe323XeCGezvwzAhlaqwykpKsyKhzVzriyJ0PzWl9mg

