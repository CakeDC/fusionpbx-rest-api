<script type="text/javascript">
function copy(data) {
    navigator.clipboard.writeText(data).then(() => {
        // TODO: positive feedback
    }).catch((e) => {
        // TODO: negative feedback
    });
}
</script>
<?php
require_once "resources/footer.php";
