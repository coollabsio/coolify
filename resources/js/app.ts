import "../css/app.css";
import { createInertiaApp } from "@inertiajs/svelte";
import Layout from "./layouts/Layout.svelte";

createInertiaApp({
	layout: () => Layout,
}).catch(console.error);
