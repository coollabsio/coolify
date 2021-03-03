<script>
  import { params,  redirect } from "@roxi/routify";
  $redirect(`/application/${$params.organization}/${$params.name}/${$params.branch}/overview`);
</script>
