<?php
// new_ufmhrm/templates/footer.php
?>
        </div>
    </main>

<?php if (isLoggedIn()): ?>
    <footer class="bg-white border-t border-gray-200">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row justify-between items-center text-sm text-gray-500">
                <p>&copy; <?php echo date('Y'); ?> Ujjal Flower Mills. All rights reserved.</p>
                <p>Version <?php echo APP_VERSION; ?></p>
            </div>
        </div>
    </footer>
<?php endif; ?>

<script src="<?php echo asset('js/app.js'); ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

</body>
</html>