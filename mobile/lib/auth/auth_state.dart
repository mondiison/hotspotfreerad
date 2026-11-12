import '../models/app_user.dart';

enum AuthStatus { bootstrapping, unauthenticated, authenticated }

class AuthState {
  final AuthStatus status;
  final AppUser? user;

  const AuthState({required this.status, this.user});

  const AuthState.bootstrapping() : this(status: AuthStatus.bootstrapping);

  const AuthState.unauthenticated() : this(status: AuthStatus.unauthenticated);

  const AuthState.authenticated(AppUser user)
      : this(status: AuthStatus.authenticated, user: user);
}
