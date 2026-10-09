<!-- jQuery -->
<script src="assets/js/lib/jquery-3.7.1.min.js"></script>
<!-- Bootstrap Bundle -->
<script src="assets/js/lib/bootstrap.bundle.min.js"></script>
<!-- Apex Charts -->
<script src="assets/js/lib/apexcharts.min.js"></script>
<!-- Data Tables -->
<script src="assets/js/lib/simple-datatables.min.js"></script>
<!-- Flatpickr -->
<!-- <script src="assets/js/lib/flatpickr.js"></script> -->
<!-- Full Calendar -->
<!-- <script src="assets/js/lib/full-calendar.js"></script> -->
<!-- Vector Map -->
<script src="assets/js/lib/jquery-jvectormap-2.0.5.min.js"></script>
<script src="assets/js/lib/jquery-jvectormap-world-mill-en.js"></script>
<!-- Magnific Popup -->
<script src="assets/js/lib/magnifc-popup.min.js"></script>
<!-- Slick Slider -->
<script src="assets/js/lib/slick.min.js"></script>
<!-- Prism -->
<script src="assets/js/lib/prism.js"></script>
<!-- File Upload -->
<script src="assets/js/lib/file-upload.js"></script>
<!-- Main JS -->
<!-- <script src="assets/js/main.js"></script> -->
 <script src="assets/js/flowbite.min.js"></script>
<!-- App JS -->
<script src="assets/js/app.js"></script>

<?php if (basename($_SERVER['PHP_SELF']) === 'dashboard.php'): ?>
<script src="assets/js/homeOneChart.js"></script>
<?php endif; ?>

<?php if (!empty($script)): ?>

<?= trim((string)$script) ?>

<?php endif; ?>


