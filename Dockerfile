# Use the official PrestaShop image as the base image
FROM prestashop/prestashop:8.2.0

# Set environment variables for non-interactive installation
ENV DEBIAN_FRONTEND=noninteractive

# Install Git using apt
RUN apt-get update && apt-get install -y git && apt-get clean

# Set Git default branch to 'main' and mark /var/www/html as a safe directory
RUN git config --global init.defaultBranch main && \
    git config --global --add safe.directory /var/www/html

# Copy local file(s)
COPY ./deployer /tmp/deployer

RUN chown -R www-data:www-data /tmp/deployer/ && \
    chmod -R 755 /tmp/deployer/

# Expose the default PrestaShop port
EXPOSE 80

# Create a startup script
COPY <<'EOF' /usr/local/bin/deployer-startup.sh
#!/bin/bash
cp -r /tmp/deployer/* /var/www/html/deployer/
chown -R www-data:www-data /var/www/html/deployer
chmod -R 755 /var/www/html/deployer
exec apache2-foreground
EOF

RUN chmod +x /usr/local/bin/deployer-startup.sh

# Start the PrestaShop entrypoint script
CMD ["/usr/local/bin/deployer-startup.sh"]