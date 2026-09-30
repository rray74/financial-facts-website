    </main>

    <footer class="border-t-2 border-primary">
        <div class="max-w-4xl mx-auto px-6 py-8 text-sm">
            <?php // Links to the trust pages appear site-wide, which is where readers and search engines look for them. ?>
            <nav class="flex flex-wrap gap-x-6 gap-y-2 mb-4">
                <a href="/about/" class="hover:text-accent">About</a>
                <a href="/how-we-check-our-figures/" class="hover:text-accent">How we check our figures</a>
                <a href="/disclaimer/" class="hover:text-accent">Disclaimer</a>
                <a href="/privacy/" class="hover:text-accent">Privacy</a>
            </nav>
            <div class="flex flex-col sm:flex-row justify-between gap-4">
                <p>&copy; <?= date('Y') ?> Financial Facts. Every figure is sourced and dated individually.</p>
                <p class="font-mono text-xs opacity-70">General information, not financial advice.</p>
            </div>
        </div>
    </footer>

</body>
</html>
