/// GET /api/routers/{id}/credentials -- the router's tunnel IP(s) plus a
/// dedicated, read-only RouterOS login for the admin's own Winbox/SSH/
/// WebFig client. See Api\RouterConsoleController::credentials() for why
/// this is deliberately not a console proxy: the admin's own device still
/// needs real network reachability to one of these IPs (their own
/// WireGuard connection, or being on-site) -- this only ever supplies the
/// login, never a connection.
class RouterCredentials {
  final String? wireguardIp;
  final String? zerotierIp;
  final int winboxPort;
  final int sshPort;
  final String username;
  final String password;
  final String note;

  RouterCredentials({
    required this.wireguardIp,
    required this.zerotierIp,
    required this.winboxPort,
    required this.sshPort,
    required this.username,
    required this.password,
    required this.note,
  });

  factory RouterCredentials.fromJson(Map<String, dynamic> json) {
    return RouterCredentials(
      wireguardIp: json['wireguard_ip'] as String?,
      zerotierIp: json['zerotier_ip'] as String?,
      winboxPort: json['winbox_port'] as int,
      sshPort: json['ssh_port'] as int,
      username: json['username'] as String,
      password: json['password'] as String,
      note: json['note'] as String? ?? '',
    );
  }
}
