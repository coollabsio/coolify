import "../css/app.css";
import { createInertiaApp, http, page } from "@inertiajs/svelte";
import Layout from "./layouts/Layout.svelte";

createInertiaApp({
	layout: () => Layout,
}).catch(console.error);

// Auto-append workspace query parameter to every Inertia request.
http.onRequest((config) => {
	const url = new URL(config.url, window.location.origin);

	if (!url.searchParams.has("workspace") && page.props.workspace?.id) {
		url.searchParams.set("workspace", page.props.workspace.id);
		config.url = url.pathname + url.search;
	}

	return config;
});
