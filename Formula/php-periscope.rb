# typed: false
# frozen_string_literal: true

# Homebrew tap formula for php-periscope.
#
# Lives at Formula/php-periscope.rb at repo root so `brew tap` finds it. To consume:
#
#   brew tap thamibn/php-periscope https://github.com/thamibn/php-periscope.git
#   brew install --HEAD thamibn/php-periscope/php-periscope
#
# Once the formula stabilises we'll move it into a dedicated
# `thamibn/homebrew-php-periscope` tap repo and remove this copy.
class PhpPeriscope < Formula
  desc "Live observability + time-travel debugger for PHP/Laravel"
  homepage "https://github.com/thamibn/php-periscope"
  url "https://github.com/thamibn/php-periscope/archive/refs/tags/v0.1.0.tar.gz"
  version "0.1.0"
  # Update sha256 at release time; placeholder for the v0.1.0-dev branch.
  sha256 "0000000000000000000000000000000000000000000000000000000000000000"
  license "Proprietary"

  head "https://github.com/thamibn/php-periscope.git", branch: "main"

  depends_on "pkg-config" => :build
  depends_on "rust" => :build
  depends_on "capnp"

  # PHP 8.3 is the v1 baseline; 8.4 is also supported. Brew tap formulas
  # may pull in optional php variants — we treat at least one as required
  # below so the install never silently skips the extension build.
  depends_on "php" => :recommended

  def install
    # --- 1. Rust daemon -------------------------------------------------
    cd "daemon" do
      system "cargo", "install", *std_cargo_args
      bin.install "target/release/periscope-dump"   if File.exist?("target/release/periscope-dump")
      bin.install "target/release/periscope-export" if File.exist?("target/release/periscope-export")
    end

    # --- 2. C extension, once per detected brew PHP --------------------
    detected = detect_brew_phps
    odie <<~ERR if detected.empty?
      No brew-installed PHP found. Install one first:
        brew install php
      then re-run:
        brew reinstall periscopephp/php-periscope/php-periscope
    ERR

    detected.each do |php_formula|
      php = Formula[php_formula]
      php_config = php.opt_bin/"php-config"
      phpize     = php.opt_bin/"phpize"

      cd "extension" do
        system "make", "distclean" if File.exist?("Makefile")
        system phpize.to_s
        system "./configure", "--with-php-config=#{php_config}"
        system "make", "-j#{ENV.make_jobs}"

        # Drop the .so into PHP's pecl extension dir for the matching version.
        ext_dir = Utils.safe_popen_read(php.opt_bin/"php", "-r", 'echo ini_get("extension_dir");').strip
        odie "Could not detect extension_dir for #{php_formula}" if ext_dir.empty?
        mkdir_p ext_dir
        cp "modules/periscope.so", "#{ext_dir}/periscope.so"
      end
    end

    # Stash the install script so brew users can re-run config easily.
    bin.install "scripts/install.sh"   => "periscope-reinstall-config"
    bin.install "scripts/uninstall.sh" => "periscope-uninstall-config"
  end

  def caveats
    detected = detect_brew_phps
    paths = detected.map { |f| "  #{Formula[f].opt_etc}/php/#{f.split("@").last}/conf.d/99-periscope.ini" }.join("\n")
    <<~CAVEATS
      php-periscope installed:
        - daemon binaries → #{HOMEBREW_PREFIX}/bin/periscope-{daemon,dump,export}
        - extension .so   → installed into each brew PHP's extension_dir

      One more step — drop a 99-periscope.ini into each brew PHP's
      `conf.d` so the extension auto-loads on every SAPI:

      #{paths.empty? ? "  (no brew PHP detected — install php first)" : paths}

      Easiest way to do that is to run:
        periscope-reinstall-config

      It detects the same PHPs we did and writes the ini for you.

      Then:
        periscope-daemon        # starts the HTTP+DAP server on :9999
        composer require periscopephp/laravel   # in any Laravel project
        claude mcp add periscope -- php artisan mcp:start periscope
    CAVEATS
  end

  test do
    # The daemon's --version flag prints a sensible version string.
    assert_match(/periscope/i, shell_output("#{bin}/periscope-daemon --version"))

    # If a brew PHP is around, verify the extension was dropped into its
    # extension_dir. We deliberately don't enable it here — that's the
    # user's call via 99-periscope.ini.
    detect_brew_phps.each do |php_formula|
      php = Formula[php_formula]
      ext_dir = Utils.safe_popen_read(php.opt_bin/"php", "-r", 'echo ini_get("extension_dir");').strip
      next if ext_dir.empty?

      assert_path_exists "#{ext_dir}/periscope.so",
                         "periscope.so missing from #{php_formula}'s extension_dir"
    end
  end

  private

  # Return the list of brew-installed PHP formula names we should build
  # the extension against. Covers the unversioned `php`, plus pinned
  # `php@8.3` / `php@8.4` if present.
  def detect_brew_phps
    candidates = %w[php php@8.3 php@8.4]
    candidates.select do |name|
      Formula[name].any_version_installed?
    rescue
      false
    end
  end
end
