sudo cp -f ./config/base_clients_api_testing.conf /etc/supervisor/conf.d/base_clients_api_testing.conf
sudo systemctl restart supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start base_clients_api:*
sudo supervisorctl status
